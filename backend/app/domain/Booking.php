<?php
declare(strict_types=1);
namespace app\domain;

final class Booking
{
    public static function quote(array $user, array $input): array
    {
        $pickup = (string) ($input['pickupId'] ?? '');
        $dropoff = (string) ($input['dropoffId'] ?? '');
        $count = $input['passengers'] ?? 1;
        if (!is_int($count) || $count < 1 || $count > 1000 || $pickup === $dropoff) {
            throw new AppError('INVALID_TRIP', '请选择不同上下车点和有效人数');
        }
        foreach ([$pickup, $dropoff] as $id) {
            if (!Db::one('SELECT id FROM points WHERE id=? AND enabled=1', [$id])) {
                throw new AppError('POINT_REQUIRED', '请选择具体虚拟点，点组不能直接下单');
            }
        }
        $companies = Db::all(
            'SELECT c.* FROM companies c JOIN company_points p ON c.id=p.company_id JOIN company_points d ON c.id=d.company_id WHERE c.enabled=1 AND p.point_id=? AND d.point_id=?',
            [$pickup, $dropoff],
        );
        $from = Db::all('SELECT platform_id FROM point_platforms WHERE point_id=?', [$pickup]);
        $to = Db::all('SELECT platform_id FROM point_platforms WHERE point_id=?', [$dropoff]);
        $offers = [];
        $unavailable = [];
        foreach ($companies as $company) {
            $rules = Fare::validate(Db::data($company['fare']));
            $found = false;
            foreach (
                Db::all('SELECT * FROM centers WHERE company_id=? AND enabled=1', [$company['id']])
                as $center
            ) {
                foreach (Db::data($center['depots']) as $depot) {
                    foreach (
                        Db::all('SELECT * FROM vehicles WHERE company_id=? AND capacity>=?', [
                            $company['id'],
                            $count,
                        ])
                        as $vehicle
                    ) {
                        $best = null;
                        foreach ($from as $a) {
                            foreach ($to as $b) {
                                if ($a['platform_id'] === $b['platform_id']) {
                                    continue;
                                }
                                $params = array_merge(Db::data($vehicle['config']), [
                                    'depotId' => $depot['depotId'],
                                    'sidingId' => $depot['sidingId'],
                                    'pickupPlatformId' => $a['platform_id'],
                                    'dropoffPlatformId' => $b['platform_id'],
                                    'passengerCount' => $count,
                                    'lowSpeedKmh' => 10,
                                ]);
                                try {
                                    $route = Bridge::command('taxi.quote', $params);
                                    if (
                                        !isset(
                                            $route['pickupDistanceMeters'],
                                            $route['returnDistanceMeters'],
                                        )
                                    ) {
                                        throw new AppError(
                                            'MOD_UPGRADE_REQUIRED',
                                            '模组需要支持接客与回库里程',
                                            409,
                                        );
                                    }
                                    $fare = Fare::calculate(
                                        $rules,
                                        $route['distanceMeters'],
                                        $route['estimatedSeconds'],
                                        $route['pickupDistanceMeters'],
                                        $route['returnDistanceMeters'],
                                    );
                                    $offer = [
                                        'id' => Db::id(),
                                        'companyId' => $company['id'],
                                        'companyName' => $company['name'],
                                        'centerId' => $center['id'],
                                        'centerName' => $center['name'],
                                        'vehicleId' => $vehicle['id'],
                                        'vehicleName' => $vehicle['name'],
                                        'capacity' => (int) $vehicle['capacity'],
                                        'rules' => $rules,
                                        'route' => $route,
                                        'fare' => $fare,
                                        'params' => $params,
                                    ];
                                    if (
                                        $best === null ||
                                        $route['pickupDistanceMeters'] <
                                            $best['route']['pickupDistanceMeters']
                                    ) {
                                        $best = $offer;
                                    }
                                } catch (AppError $e) {
                                    if (
                                        in_array(
                                            $e->key,
                                            [
                                                'UPSTREAM_UNCERTAIN',
                                                'GATEWAY_NOT_CONFIGURED',
                                                'MOD_UPGRADE_REQUIRED',
                                            ],
                                            true,
                                        )
                                    ) {
                                        throw $e;
                                    }
                                }
                            }
                        }
                        if ($best) {
                            $offers[] = $best;
                            $found = true;
                        }
                    }
                }
            }
            if (!$found) {
                $unavailable[] = [
                    'companyId' => $company['id'],
                    'name' => $company['name'],
                    'reason' => '暂无满足人数且全程连通的车型与轨道',
                ];
            }
        }
        usort(
            $offers,
            fn($a, $b) => $a['route']['pickupDistanceMeters'] <=>
                $b['route']['pickupDistanceMeters'],
        );
        $id = Db::id();
        $expires = time() + 300;
        Db::insert('quotes', [
            'id' => $id,
            'user_id' => $user['id'],
            'pickup_id' => $pickup,
            'dropoff_id' => $dropoff,
            'passengers' => $count,
            'offers' => Db::json($offers),
            'expires' => $expires,
        ]);
        return [
            'id' => $id,
            'expires' => $expires,
            'offers' => array_map(self::publicOffer(...), $offers),
            'unavailable' => $unavailable,
        ];
    }
    public static function publicOffer(array $o): array
    {
        unset($o['params']);
        return $o;
    }
    public static function create(array $user, array $input, string $key): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,128}$/D', $key)) {
            throw new AppError('IDEMPOTENCY_REQUIRED', '缺少有效的幂等请求标识');
        }
        $companies = $input['companyIds'] ?? [];
        if (
            !is_array($companies) ||
            !$companies ||
            array_filter($companies, fn($v) => !is_string($v))
        ) {
            throw new AppError('COMPANY_REQUIRED', '请选择至少一个运营企业');
        }
        $companies = array_values(array_unique($companies));
        sort($companies);
        $offerIds = $input['offerIds'] ?? [];
        if (!is_array($offerIds) || array_filter($offerIds, fn($v) => !is_string($v))) {
            throw new AppError('INVALID_OFFER', '车型方案无效');
        }
        $offerIds = array_values(array_unique($offerIds));
        sort($offerIds);
        $fingerprint = hash(
            'sha256',
            Db::json([
                (string) ($input['quoteId'] ?? ''),
                $companies,
                (string) ($input['vehicleId'] ?? ''),
                $offerIds,
            ]),
        );
        $existing = Db::one('SELECT * FROM orders WHERE user_id=? AND idempotency_key=?', [
            $user['id'],
            $key,
        ]);
        if ($existing) {
            if ($existing['request_hash'] !== $fingerprint) {
                throw new AppError('ORDER_CONFLICT', '请求标识已用于其他参数', 409);
            }
            return self::view($existing);
        }
        $q = Db::one('SELECT * FROM quotes WHERE id=? AND user_id=?', [
            $input['quoteId'] ?? '',
            $user['id'],
        ]);
        if (!$q || $q['expires'] <= time()) {
            throw new AppError('QUOTE_EXPIRED', '报价已过期，请重新询价', 409);
        }
        $offers = array_values(
            array_filter(
                Db::data($q['offers']),
                fn($o) => in_array($o['companyId'], $companies, true) &&
                    (!$offerIds || in_array($o['id'], $offerIds, true)) &&
                    (empty($input['vehicleId']) || $o['vehicleId'] === $input['vehicleId']),
            ),
        );
        if (!$offers || array_diff($companies, array_column($offers, 'companyId'))) {
            throw new AppError('INVALID_OFFER', '所选企业或车型不在本次报价内');
        }
        $prepay = (bool) array_filter($offers, fn($o) => $o['rules']['payment'] !== 'after');
        return Db::tx(function () use ($q, $user, $key, $fingerprint, $offers, $prepay) {
            $now = time();
            $id = Db::id();
            Db::run('UPDATE dispatch_sequence SET ticket=ticket+1 WHERE id=1');
            // Serialize concurrent retries before inserting the unique user/key pair.
            $existing = Db::one(
                'SELECT * FROM orders WHERE user_id=? AND idempotency_key=?' . Db::lock(),
                [$user['id'], $key],
            );
            if ($existing) {
                if ($existing['request_hash'] !== $fingerprint) {
                    throw new AppError('ORDER_CONFLICT', '请求标识已用于其他参数', 409);
                }
                return self::view($existing);
            }
            $ticket = (int) Db::one('SELECT ticket FROM dispatch_sequence WHERE id=1')['ticket'];
            $snapshot = [
                'pickup' => Db::one('SELECT name FROM points WHERE id=?', [$q['pickup_id']])[
                    'name'
                ],
                'dropoff' => Db::one('SELECT name FROM points WHERE id=?', [$q['dropoff_id']])[
                    'name'
                ],
                'offers' => array_map(self::publicOffer(...), $offers),
            ];
            $order = [
                'id' => $id,
                'user_id' => $user['id'],
                'idempotency_key' => $key,
                'request_hash' => $fingerprint,
                'pickup_id' => $q['pickup_id'],
                'dropoff_id' => $q['dropoff_id'],
                'passengers' => $q['passengers'],
                'state' => $prepay ? 'AWAITING_PAYMENT' : 'QUEUED',
                'snapshot' => Db::json($snapshot),
                'telemetry' => '{}',
                'amount' => max(array_column(array_column($offers, 'fare'), 'amountMinor')),
                'paid' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'queue_until' => $now + (int) Env::get('QUEUE_TIMEOUT_SECONDS', '300'),
            ];
            $order['queue_ticket'] = $ticket;
            Db::insert('orders', $order);
            foreach ($offers as $o) {
                $params = $o['params'];
                unset($params['lowSpeedKmh']);
                $params['orderId'] = $id;
                $params['routeName'] =
                    'TAXILink · ' . $snapshot['pickup'] . ' → ' . $snapshot['dropoff'];
                Db::insert('candidates', [
                    'id' => Db::id(),
                    'order_id' => $id,
                    'company_id' => $o['companyId'],
                    'center_id' => $o['centerId'],
                    'pickup_distance' => $o['route']['pickupDistanceMeters'],
                    'state' => 'WAITING',
                    'params' => Db::json($params),
                    'offer' => Db::json($o),
                ]);
            }
            self::event($id, 'order.created', ['demonstration' => true]);
            return self::view($order);
        });
    }
    public static function view(array $o): array
    {
        $snapshot = Db::data($o['snapshot']);
        $t = Db::data($o['telemetry']);
        return [
            'id' => $o['id'],
            'revision' => (int) ($o['revision'] ?? 0),
            'state' => $o['state'],
            'pickup' => $snapshot['pickup'],
            'dropoff' => $snapshot['dropoff'],
            'passengers' => (int) $o['passengers'],
            'companyId' => $o['company_id'] ?? null,
            'amountMinor' => (int) $o['amount'],
            'paidMinor' => (int) $o['paid'],
            'createdAt' => (int) $o['created_at'],
            'billingStarted' => isset($o['billing_started']) ? (int) $o['billing_started'] : null,
            'telemetry' => $t,
            'offers' => $snapshot['offers'],
            'demonstration' => true,
            'queueUntil' => (int) $o['queue_until'],
        ];
    }
    public static function event(
        string $order,
        string $type,
        array $data = [],
        ?string $key = null,
    ): void {
        $key ??= Db::id();
        if (Db::one('SELECT id FROM events WHERE event_key=?', [$key])) {
            return;
        }
        Db::insert('events', [
            'id' => Db::id(),
            'order_id' => $order,
            'event_key' => $key,
            'event_type' => $type,
            'data' => Db::json($data),
            'created_at' => time(),
        ]);
    }
    public static function pay(array $user, string $id): array
    {
        return Db::tx(function () use ($user, $id) {
            Auth::order($user, $id);
            $o = Db::one('SELECT * FROM orders WHERE id=?' . Db::lock(), [$id]);
            if (
                !in_array(
                    $o['state'],
                    ['AWAITING_PAYMENT', 'COMPLETED', 'CANCELLED', 'FAILED'],
                    true,
                )
            ) {
                throw new AppError('PAYMENT_NOT_DUE', '当前订单无需付款', 409);
            }
            $difference = (int) $o['amount'] - (int) $o['paid'];
            if ($difference !== 0) {
                Db::insert('payments', [
                    'id' => Db::id(),
                    'order_id' => $id,
                    'kind' => $difference > 0 ? 'DEMO_PAY' : 'DEMO_REFUND',
                    'amount' => abs($difference),
                    'created_at' => time(),
                    'demonstration' => 1,
                ]);
            }
            Db::run(
                'UPDATE orders SET revision=revision+1,paid=amount,state=?,updated_at=?,queue_until=? WHERE id=?',
                [
                    $o['state'] === 'AWAITING_PAYMENT' ? 'QUEUED' : $o['state'],
                    time(),
                    $o['state'] === 'AWAITING_PAYMENT'
                        ? time() + (int) Env::get('QUEUE_TIMEOUT_SECONDS', '300')
                        : $o['queue_until'],
                    $id,
                ],
            );
            self::event($id, 'payment.demonstrated', ['amountMinor' => $difference]);
            return self::view(Db::one('SELECT * FROM orders WHERE id=?', [$id]));
        });
    }
}
