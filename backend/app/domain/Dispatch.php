<?php
declare(strict_types=1);
namespace app\domain;

/** One process owns all cross-company allocation. No independent mod center queues. */
final class Dispatch
{
    public static function tick(): void
    {
        foreach (
            Db::all('SELECT * FROM mod_inbox WHERE processed=0 ORDER BY created_at,id LIMIT 200')
            as $event
        ) {
            $m = Db::data($event['data']);
            $d = $m['data'] ?? [];
            if (isset($d['orderId'])) {
                self::ingest(
                    $d['orderId'],
                    $m['type'] ?? '',
                    $d,
                    (int) ($m['timestamp'] ?? time() * 1000),
                );
            }
            Db::run('UPDATE mod_inbox SET processed=1 WHERE id=?', [$event['id']]);
        }
        foreach (
            Db::all(
                "SELECT * FROM orders WHERE state IN ('DISPATCHING','ACTIVE','CANCEL_REQUESTED','NEEDS_ATTENTION','FINALIZING') ORDER BY created_at LIMIT 100",
            )
            as $o
        ) {
            try {
                $status = Bridge::command('taxi.status', ['orderId' => $o['id']]);
                self::ingest($o['id'], 'reconcile.status', $status, time() * 1000);
                try {
                    $params = ['orderId' => $o['id'], 'offset' => 0];
                    do {
                        $logs = Bridge::command('taxi.logs', $params);
                        foreach ($logs['items'] ?? [] as $log) {
                            self::ingest(
                                $o['id'],
                                $log['type'],
                                isset($log['stage']) ? ['stage' => $log['stage']] : [],
                                (int) $log['timestamp'],
                                (string) $log['sequence'],
                            );
                        }
                        $params['offset'] = $logs['nextOffset'] ?? null;
                    } while ($params['offset'] !== null);
                    try {
                        $status['geometry'] = Bridge::command('taxi.geometry', [
                            'orderId' => $o['id'],
                        ]);
                    } catch (AppError $e) {
                        if ($e->key !== 'VEHICLE_NOT_READY') {
                            throw $e;
                        }
                    }
                    self::ingest($o['id'], 'reconcile.final', $status, time() * 1000);
                } catch (AppError $e) {
                    if (!in_array($e->key, ['VEHICLE_NOT_READY', 'ORDER_NOT_FOUND'], true)) {
                        throw $e;
                    }
                }
                $current = Db::one('SELECT * FROM orders WHERE id=?', [$o['id']]);
                if ($current['state'] === 'CANCEL_REQUESTED') {
                    try {
                        $result = Bridge::command('taxi.destroy', ['orderId' => $o['id']]);
                        self::ingest($o['id'], 'ride.destroyed', $result, time() * 1000);
                    } catch (AppError $e) {
                        if (in_array($e->key, ['VEHICLE_OCCUPIED', 'VEHICLE_MOVING'], true)) {
                            Db::run(
                                "UPDATE orders SET state='NEEDS_ATTENTION',updated_at=? WHERE id=?",
                                [time(), $o['id']],
                            );
                            Booking::event($o['id'], 'cancel.requires_assistance', [
                                'reason' => $e->key,
                            ]);
                        } else {
                            throw $e;
                        }
                    }
                }
            } catch (AppError $e) {
                if ($e->key === 'ORDER_NOT_FOUND' && $o['state'] === 'CANCEL_REQUESTED') {
                    self::finish($o, 'CANCELLED', 'cancel.not_dispatched', 0);
                    continue;
                }
                if ($e->key === 'ORDER_NOT_FOUND' && $o['state'] === 'DISPATCHING') {
                    // Same exact request is retried; never switch depots after an ambiguous result.
                    $candidate = Db::one('SELECT * FROM candidates WHERE id=?', [
                        $o['candidate_id'],
                    ]);
                    if ($candidate) {
                        try {
                            $r = Bridge::command('taxi.create', Db::data($candidate['params']));
                            self::ingest($o['id'], 'reconcile.create', $r, time() * 1000);
                        } catch (AppError $retry) {
                            if (
                                in_array(
                                    $retry->key,
                                    ['DEPOT_BUSY', 'CAPACITY_EXCEEDED', 'SERVER_BUSY'],
                                    true,
                                )
                            ) {
                                Db::run(
                                    "UPDATE orders SET state='QUEUED',candidate_id=NULL,company_id=NULL WHERE id=? AND state='DISPATCHING'",
                                    [$o['id']],
                                );
                            } elseif (
                                in_array(
                                    $retry->key,
                                    [
                                        'INVALID_REQUEST',
                                        'MTR_ID_NOT_FOUND',
                                        'DEPOT_NOT_ALLOWED',
                                        'DEDICATED_DEPOT_REQUIRED',
                                        'INVALID_ROUTE',
                                        'VEHICLE_TOO_LONG',
                                        'INSUFFICIENT_CAPACITY',
                                    ],
                                    true,
                                )
                            ) {
                                Db::run("UPDATE candidates SET state='REJECTED' WHERE id=?", [
                                    $candidate['id'],
                                ]);
                                Db::run(
                                    "UPDATE orders SET state='QUEUED',candidate_id=NULL,company_id=NULL WHERE id=? AND state='DISPATCHING'",
                                    [$o['id']],
                                );
                            }
                        };
                    }
                }
            }
        }
        foreach (
            Db::all(
                "SELECT * FROM orders WHERE state='QUEUED' ORDER BY queue_ticket,created_at,id LIMIT 50",
            )
            as $o
        ) {
            if ($o['queue_until'] <= time()) {
                self::finish($o, 'CANCELLED', 'queue.expired', 0);
                continue;
            }
            $waiting = Db::all(
                "SELECT * FROM candidates WHERE order_id=? AND state='WAITING' ORDER BY pickup_distance,id",
                [$o['id']],
            );
            if (!$waiting) {
                self::finish($o, 'FAILED', 'dispatch.no_candidates', 0);
                continue;
            }
            foreach ($waiting as $c) {
                $offer = Db::data($c['offer']);
                if (
                    !Db::one('SELECT id FROM companies WHERE id=? AND enabled=1', [
                        $c['company_id'],
                    ]) ||
                    !Db::one('SELECT id FROM centers WHERE id=? AND company_id=? AND enabled=1', [
                        $c['center_id'],
                        $c['company_id'],
                    ])
                ) {
                    Db::run("UPDATE candidates SET state='REJECTED' WHERE id=?", [$c['id']]);
                    continue;
                }
                if (
                    Db::run(
                        "UPDATE orders SET state='DISPATCHING',candidate_id=?,company_id=?,amount=?,updated_at=? WHERE id=? AND state='QUEUED'",
                        [
                            $c['id'],
                            $c['company_id'],
                            $offer['fare']['amountMinor'],
                            time(),
                            $o['id'],
                        ],
                    )->rowCount() !== 1
                ) {
                    break;
                }
                try {
                    $r = Bridge::command('taxi.create', Db::data($c['params']));
                    Db::run(
                        "UPDATE candidates SET state=CASE WHEN id=? THEN 'SELECTED' ELSE 'CANCELLED' END WHERE order_id=?",
                        [$c['id'], $o['id']],
                    );
                    self::ingest($o['id'], 'dispatch.accepted', $r, time() * 1000);
                    break;
                } catch (AppError $e) {
                    if (
                        in_array($e->key, ['DEPOT_BUSY', 'CAPACITY_EXCEEDED', 'SERVER_BUSY'], true)
                    ) {
                        Db::run(
                            "UPDATE orders SET state='QUEUED',candidate_id=NULL,company_id=NULL WHERE id=? AND state='DISPATCHING'",
                            [$o['id']],
                        );
                        continue;
                    }
                    if (
                        in_array(
                            $e->key,
                            [
                                'UPSTREAM_UNCERTAIN',
                                'MOD_RESPONSE_TIMEOUT',
                                'MOD_DISCONNECTED',
                                'MOD_RECONNECTED',
                                'MOD_OFFLINE',
                            ],
                            true,
                        )
                    ) {
                        break;
                    }
                    // Other create failures may already have a write-ahead mod order. Reconcile before any new allocation.
                    Booking::event($o['id'], 'dispatch.requires_reconciliation', [
                        'reason' => $e->key,
                    ]);
                    break;
                }
            }
        }
    }
    public static function ingest(
        string $id,
        string $type,
        array $data,
        int $timestamp,
        ?string $sequence = null,
    ): void {
        Db::tx(fn() => self::apply($id, $type, $data, $timestamp, $sequence));
    }
    private static function apply(
        string $id,
        string $type,
        array $data,
        int $timestamp,
        ?string $sequence,
    ): void {
        $o = Db::one('SELECT * FROM orders WHERE id=?' . Db::lock(), [$id]);
        if (!$o) {
            return;
        }
        if ($sequence !== null) {
            $key = 'mod:' . $id . ':' . $sequence;
            if (Db::one('SELECT id FROM events WHERE event_key=?', [$key])) {
                return;
            }
            Booking::event($id, $type, $data, $key);
        } elseif (!str_starts_with($type, 'reconcile.') && $type !== 'taxi.telemetry') {
            Booking::event(
                $id,
                $type,
                $data,
                'wire:' . hash('sha256', $id . $type . $timestamp . Db::json($data)),
            );
        }
        if (in_array($o['state'], ['COMPLETED', 'CANCELLED', 'FAILED'], true)) {
            return;
        }
        $telemetry = array_replace(Db::data($o['telemetry']), $data);
        $telemetry['_everOnboard'] =
            ($telemetry['_everOnboard'] ?? false) ||
            ($data['onboardCount'] ?? 0) > 0 ||
            $type === 'passenger.boarded';
        $start = $o['billing_started'];
        $end = $o['billing_ended'];
        $at = (int) floor($timestamp / 1000);
        if ($type === 'ride.pickup_departure_released' || ($data['stage'] ?? '') === 'ON_TRIP') {
            $start = $start === null ? $at : min((int) $start, $at);
        }
        if (
            $start !== null &&
            in_array($data['stage'] ?? '', ['WAITING_DROPOFF', 'RETURNING'], true)
        ) {
            $end = $end === null ? $at : min((int) $end, $at);
        }
        $state = $o['state'];
        if (
            !in_array($state, ['COMPLETED', 'CANCELLED', 'FAILED'], true) &&
            !in_array($state, ['CANCEL_REQUESTED', 'NEEDS_ATTENTION'], true)
        ) {
            $state = 'ACTIVE';
        }
        $phase = $data['phase'] ?? '';
        if ($phase !== '' && $o['candidate_id']) {
            Db::run(
                "UPDATE candidates SET state=CASE WHEN id=? THEN 'SELECTED' ELSE 'CANCELLED' END WHERE order_id=? AND state='WAITING'",
                [$o['candidate_id'], $id],
            );
        }
        if ($phase === 'COMPLETED') {
            $state = $type === 'reconcile.final' ? 'COMPLETED' : 'FINALIZING';
            $end ??= $at;
        } elseif ($phase === 'DESTROYED' || $phase === 'CANCELLED') {
            $state = $type === 'reconcile.final' ? 'CANCELLED' : 'FINALIZING';
            $end ??= $at;
        } elseif ($phase === 'FAILED') {
            $state = $type === 'reconcile.final' ? 'FAILED' : 'FINALIZING';
            $end ??= $at;
        } elseif ($phase === 'RECOVERED') {
            $state = 'NEEDS_ATTENTION';
            $end ??= $at;
        }
        $amount = (int) $o['amount'];
        $c = $o['candidate_id']
            ? Db::one('SELECT offer FROM candidates WHERE id=?', [$o['candidate_id']])
            : null;
        if ($c) {
            $offer = Db::data($c['offer']);
            $rules = $offer['rules'];
            if ($rules['mode'] === 'fixed') {
                $amount = (int) $offer['fare']['amountMinor'];
            }
            if ($start !== null && $rules['mode'] === 'metered') {
                $geo = $telemetry['geometry'] ?? [];
                $distance = 0;
                foreach ($geo['legs'] ?? [] as $leg) {
                    if ($leg['stage'] === 'ON_TRIP') {
                        $distance = min(
                            max(0, ($geo['progressMeters'] ?? 0) - $leg['startMeters']),
                            $leg['endMeters'] - $leg['startMeters'],
                        );
                    }
                }
                $pickup = $offer['route']['pickupDistanceMeters'];
                $return = $offer['route']['returnDistanceMeters'];
                foreach ($geo['legs'] ?? [] as $leg) {
                    if ($leg['stage'] === 'TO_PICKUP') {
                        $pickup = max(0, $leg['endMeters'] - $leg['startMeters']);
                    }
                    if ($leg['stage'] === 'RETURNING') {
                        $return = max(0, $leg['endMeters'] - $leg['startMeters']);
                    }
                }
                $amount = Fare::calculate(
                    $rules,
                    $distance,
                    max(0, ($end ?? time()) - (int) $start),
                    $pickup,
                    $return,
                )['amountMinor'];
            }
            if ($state === 'FAILED') {
                $amount = 0;
            }
            if ($state === 'CANCELLED' && $start === null) {
                $amount = $rules['cancelMinor'];
            }
            if (
                $state === 'COMPLETED' &&
                !($telemetry['_everOnboard'] ?? false) &&
                !Db::one(
                    "SELECT id FROM events WHERE order_id=? AND event_type='passenger.depart_requested'",
                    [$id],
                )
            ) {
                $amount = $rules['noShowMinor'];
            }
        }
        Db::run(
            'UPDATE orders SET state=?,telemetry=?,billing_started=?,billing_ended=?,amount=?,updated_at=? WHERE id=?',
            [$state, Db::json($telemetry), $start, $end, $amount, time(), $id],
        );
        if (in_array($state, ['COMPLETED', 'CANCELLED', 'FAILED'], true)) {
            Db::run(
                "UPDATE candidates SET state='CANCELLED' WHERE order_id=? AND state='WAITING'",
                [$id],
            );
            if ((int) $o['paid'] > $amount) {
                $refund = (int) $o['paid'] - $amount;
                Db::insert('payments', [
                    'id' => Db::id(),
                    'order_id' => $id,
                    'kind' => 'DEMO_REFUND',
                    'amount' => $refund,
                    'created_at' => time(),
                    'demonstration' => 1,
                ]);
                Db::run('UPDATE orders SET paid=? WHERE id=?', [$amount, $id]);
            }
        }
    }
    public static function finish(array $o, string $state, string $event, int $amount): void
    {
        $finish = static function () use ($o, $state, $event, $amount): void {
            $current = Db::one('SELECT * FROM orders WHERE id=?' . Db::lock(), [$o['id']]);
            if (!$current || $current['state'] !== $o['state']) {
                return;
            }
            Db::run('UPDATE orders SET state=?,amount=?,updated_at=? WHERE id=?', [
                $state,
                $amount,
                time(),
                $o['id'],
            ]);
            Db::run(
                "UPDATE candidates SET state='CANCELLED' WHERE order_id=? AND state='WAITING'",
                [$o['id']],
            );
            Booking::event($o['id'], $event);
            if ((int) $current['paid'] > $amount) {
                Db::insert('payments', [
                    'id' => Db::id(),
                    'order_id' => $o['id'],
                    'kind' => 'DEMO_REFUND',
                    'amount' => (int) $current['paid'] - $amount,
                    'created_at' => time(),
                    'demonstration' => 1,
                ]);
                Db::run('UPDATE orders SET paid=? WHERE id=?', [$amount, $o['id']]);
            }
        };
        if (Db::pdo()->inTransaction()) {
            $finish();
        } else {
            Db::tx($finish);
        }
    }
}
