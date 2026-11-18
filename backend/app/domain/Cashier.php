<?php
declare(strict_types=1);
namespace app\domain;

final class Cashier
{
    public static function settings(string $scope): array
    {
        $c = self::config($scope);
        foreach (['privateKey', 'keyId'] as $key) {
            $c[$key . 'Configured'] = !empty($c[$key]);
            unset($c[$key]);
        }
        return $c;
    }
    private static function config(string $scope): array
    {
        return Db::data(
            Db::one('SELECT config FROM cashier_settings WHERE id=?', [$scope])['config'] ?? null,
        );
    }
    public static function save(string $scope, array $input): array
    {
        $old = self::config($scope);
        $c = [];
        foreach (
            ['origin', 'merchant', 'keyId', 'privateKey', 'platformKey', 'returnUrl', 'environment']
            as $key
        ) {
            $c[$key] = trim((string) ($input[$key] ?? ''));
        }
        foreach (['privateKey', 'keyId'] as $key) {
            if ($c[$key] === '') {
                $c[$key] = $old[$key] ?? '';
            }
        }
        $c['enabled'] = ($input['enabled'] ?? false) === true;
        if ($c['enabled']) {
            self::url($c['origin'], true);
            self::url($c['returnUrl']);
            if (
                !preg_match('/^[a-f0-9]{32}$/D', $c['merchant']) ||
                !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $c['keyId']) ||
                !in_array($c['environment'], ['sandbox', 'live'], true)
            ) {
                throw new AppError('INVALID_CASHIER', '请填写商户号、API Key 和交易环境');
            }
            CashierCrypto::validatePublic(self::bin(), $c['platformKey']);
            // Validate the private key and curve without disclosing it.
            $e = CashierCrypto::seal(
                self::bin(),
                $c['privateKey'],
                $c['platformKey'],
                'merchant-request',
                ['test' => true],
            );
        }
        $c['origin'] = rtrim($c['origin'], '/');
        Db::tx(function () use ($scope, $c) {
            Db::run('DELETE FROM cashier_settings WHERE id=?', [$scope]);
            Db::insert('cashier_settings', ['id' => $scope, 'config' => Db::json($c)]);
        });
        return self::settings($scope);
    }
    private static function bin(): string
    {
        return Env::get('CASHIER_OPENSSL', 'openssl');
    }
    private static function url(string $url, bool $origin = false): void
    {
        $p = parse_url($url);
        if (
            !filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array($p['scheme'] ?? '', ['http', 'https'], true) ||
            isset($p['user']) ||
            isset($p['pass']) ||
            isset($p['fragment']) ||
            preg_match('/[\\\\\x00-\x20\x7f]/', $url) ||
            ($origin && (isset($p['query']) || !in_array($p['path'] ?? '', ['', '/'], true)))
        ) {
            throw new AppError('INVALID_URL', '请输入有效的收银台来源和回跳地址');
        }
    }
    public static function request(array $c, array $input): array
    {
        $request = $input + [
            'merchant' => $c['merchant'],
            'key_id' => $c['keyId'],
            'environment' => $c['environment'],
            'challenge' => Db::id(),
            'timestamp' => time(),
        ];
        $envelope = CashierCrypto::seal(
            self::bin(),
            $c['privateKey'],
            $c['platformKey'],
            'merchant-request',
            $request,
        );
        $curl = curl_init($c['origin'] . '/open/v1');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => Db::json($envelope),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Merchant-Id: ' . $c['merchant'],
                'X-Key-Id: ' . $c['keyId'],
            ],
        ]);
        try {
            $raw = curl_exec($curl);
        } finally {
            unset($curl);
        }
        if (!is_string($raw)) {
            throw new AppError('CASHIER_UNAVAILABLE', '收银台暂不可用，请稍后重试', 502);
        }
        $response = CashierCrypto::open(
            self::bin(),
            Db::data($raw),
            $c['privateKey'],
            $c['platformKey'],
            'merchant-response',
        );
        foreach (['merchant', 'key_id', 'environment', 'challenge', 'request_id'] as $key) {
            if (($response[$key] ?? null) !== ($request[$key] ?? null)) {
                throw new AppError('CASHIER_CONTEXT', '收银台响应归属不符', 502);
            }
        }
        if (!is_int($response['timestamp'] ?? null) || abs(time() - $response['timestamp']) > 300) {
            throw new AppError('CASHIER_EXPIRED', '收银台响应已过期', 502);
        }
        if (($response['ok'] ?? false) !== true) {
            throw new AppError(
                'CASHIER_REJECTED',
                '收银台拒绝请求：' . ($response['code'] ?? 'UNKNOWN'),
                502,
            );
        }
        return $response['result'];
    }
    public static function checkout(array $user, string $id): array
    {
        Auth::order($user, $id);
        self::syncOrder($id);
        $trade = Db::tx(function () use ($id) {
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
            if ($difference < 0) {
                throw new AppError('REFUND_PENDING', '退款正在处理中，请稍后刷新', 409);
            }
            if ($difference === 0) {
                self::queue($o);
                return null;
            }
            $existing = Db::one(
                "SELECT * FROM cashier_trades WHERE order_id=? AND status IN ('creating','pending','processing') ORDER BY created_at,id LIMIT 1",
                [$id],
            );
            if ($existing) {
                return $existing;
            }
            $c = $o['company_id'] ? self::config($o['company_id']) : [];
            if (!($c['enabled'] ?? false)) {
                $c = self::config('default');
            }
            if (!($c['enabled'] ?? false)) {
                throw new AppError(
                    'CASHIER_NOT_CONFIGURED',
                    '请管理员配置总商户或运营公司商户',
                    409,
                );
            }
            self::units($difference);
            $t = [
                'id' => Db::id(),
                'order_id' => $id,
                'config' => Db::json($c),
                'amount' => $difference,
                'status' => 'creating',
                'created_at' => time(),
            ];
            Db::insert('cashier_trades', $t);
            return $t;
        });
        if (!$trade) {
            return Booking::passengerView(Auth::order($user, $id));
        }
        $remote = self::refresh($trade);
        if (!in_array($remote['status'], ['pending', 'processing'], true)) {
            return Booking::passengerView(Auth::order($user, $id));
        }
        $c = Db::data($trade['config']);
        $url = $c['origin'] . '/checkout/' . $remote['id'];
        return Booking::passengerView(Auth::order($user, $id)) + ['checkoutUrl' => $url];
    }
    public static function units(int $minor): int
    {
        if ($minor < 1 || $minor % 100 !== 0 || $minor / 100 > 2000000000) {
            throw new AppError(
                'CASHIER_PRECISION',
                '收银台仅支持整数游戏币，请管理员调整计费金额',
                409,
            );
        }
        return intdiv($minor, 100);
    }
    private static function queue(array $o): void
    {
        if ($o['state'] === 'AWAITING_PAYMENT' && (int) $o['paid'] >= (int) $o['amount']) {
            Db::run(
                "UPDATE orders SET state='QUEUED',revision=revision+1,updated_at=?,queue_until=? WHERE id=?",
                [time(), time() + (int) Env::get('QUEUE_TIMEOUT_SECONDS', '300'), $o['id']],
            );
        }
    }
    private static function refresh(array $t): array
    {
        $c = Db::data($t['config']);
        $r = $t['remote_id'] ?? null;
        if (!$r) {
            $return =
                $c['returnUrl'] .
                (str_contains($c['returnUrl'], '?') ? '&' : '?') .
                'orderId=' .
                rawurlencode($t['order_id']);
            $result = self::request($c, [
                'action' => 'create',
                'kind' => 'pay',
                'request_id' => 'pay-' . $t['id'],
                'out_trade_no' => $t['id'],
                'title' => 'TAXILink 行程付款',
                'amount' => self::units((int) $t['amount']),
                'return_url' => $return,
            ]);
            $remote = $result['order'] ?? [];
        } else {
            $remote = self::request($c, ['action' => 'query', 'order_id' => $r]);
        }
        if (
            !preg_match('/^[a-f0-9]{32}$/D', $remote['id'] ?? '') ||
            ($r && $r !== $remote['id']) ||
            ($remote['out_trade_no'] ?? '') !== $t['id'] ||
            ($remote['merchant'] ?? '') !== $c['merchant'] ||
            ($remote['env'] ?? '') !== $c['environment'] ||
            ($remote['kind'] ?? '') !== 'pay' ||
            ($remote['amount'] ?? null) !== self::units((int) $t['amount'])
        ) {
            throw new AppError('CASHIER_ORDER', '收银台订单信息不符', 502);
        }
        Db::tx(function () use ($t, $remote) {
            $o = Db::one('SELECT * FROM orders WHERE id=?' . Db::lock(), [$t['order_id']]);
            $current = Db::one('SELECT * FROM cashier_trades WHERE id=?' . Db::lock(), [$t['id']]);
            $credit =
                $remote['status'] === 'paid' ? (int) $t['amount'] : (int) $current['credited'];
            if (
                $remote['status'] === 'paid' &&
                ($remote['captured'] ?? null) !== self::units((int) $t['amount'])
            ) {
                throw new AppError('CASHIER_AMOUNT', '实收金额不符', 502);
            }
            $refund = (int) ($remote['refunded'] ?? 0) * 100;
            if ($refund < (int) $current['refunded'] || $refund > $credit) {
                throw new AppError('CASHIER_REFUND', '退款金额不符', 502);
            }
            $delta = $credit - (int) $current['credited'] - $refund + (int) $current['refunded'];
            $status = $remote['status'];
            if ($status === 'pending' && ($remote['expires'] ?? PHP_INT_MAX) < time()) {
                $status = 'expired';
            }
            Db::run(
                'UPDATE cashier_trades SET remote_id=?,status=?,credited=?,refunded=? WHERE id=?',
                [$remote['id'], $status, $credit, $refund, $t['id']],
            );
            if ($delta !== 0) {
                Db::run(
                    'UPDATE orders SET paid=paid+?,revision=revision+1,updated_at=? WHERE id=?',
                    [$delta, time(), $o['id']],
                );
                Db::insert('payments', [
                    'id' => Db::id(),
                    'order_id' => $o['id'],
                    'kind' => $delta > 0 ? 'CASHIER_PAY' : 'CASHIER_REFUND',
                    'amount' => abs($delta),
                    'created_at' => time(),
                    'demonstration' => 0,
                ]);
                Booking::event($o['id'], 'payment.confirmed', [
                    'amountMinor' => $delta,
                    'tradeId' => $t['id'],
                ]);
            }
            self::queue(Db::one('SELECT * FROM orders WHERE id=?', [$o['id']]));
        });
        return $remote;
    }
    public static function syncOrder(string $id): void
    {
        // Apply the same settlement precision to orders created before this integration.
        Db::tx(function () use ($id) {
            $o = Db::one('SELECT * FROM orders WHERE id=?' . Db::lock(), [$id]);
            if (
                $o &&
                in_array(
                    $o['state'],
                    ['AWAITING_PAYMENT', 'COMPLETED', 'CANCELLED', 'FAILED'],
                    true,
                )
            ) {
                $amount = Fare::settlement((int) $o['amount']);
                if ($amount !== (int) $o['amount']) {
                    Db::run(
                        'UPDATE orders SET amount=?,revision=revision+1,updated_at=? WHERE id=?',
                        [$amount, time(), $id],
                    );
                }
            }
        });
        foreach (
            Db::all(
                "SELECT * FROM cashier_trades WHERE order_id=? AND status NOT IN ('failed','expired') ORDER BY created_at,id",
                [$id],
            )
            as $t
        ) {
            self::refresh($t);
        }
        $o = Db::one('SELECT * FROM orders WHERE id=?', [$id]);
        if (!in_array($o['state'], ['COMPLETED', 'CANCELLED', 'FAILED'], true)) {
            return;
        }
        foreach (
            Db::all(
                "SELECT * FROM cashier_trades WHERE order_id=? AND status='paid' ORDER BY created_at,id",
                [$id],
            )
            as $t
        ) {
            $c = Db::data($t['config']);
            if ($t['refund_id']) {
                $r = self::request($c, [
                    'action' => 'refund',
                    'request_id' => $t['refund_id'],
                    'order_id' => $t['remote_id'],
                    'amount' => self::units((int) $t['refund_amount']),
                ]);
                if (($r['request']['status'] ?? '') !== 'completed') {
                    continue;
                }
                Db::run(
                    'UPDATE cashier_trades SET refund_id=NULL,refund_amount=0 WHERE id=? AND refund_id=?',
                    [$t['id'], $t['refund_id']],
                );
                self::refresh($t);
            }
            // Reserve a stable refund identifier before calling the network; retry it on uncertainty.
            $refund = Db::tx(function () use ($id, $t) {
                $o = Db::one('SELECT * FROM orders WHERE id=?' . Db::lock(), [$id]);
                $current = Db::one('SELECT * FROM cashier_trades WHERE id=?' . Db::lock(), [
                    $t['id'],
                ]);
                if ($current['refund_id']) {
                    return null;
                }
                $reserved = (int) Db::one(
                    'SELECT COALESCE(SUM(refund_amount),0) AS n FROM cashier_trades WHERE order_id=?',
                    [$id],
                )['n'];
                $amount = min(
                    (int) $o['paid'] - (int) $o['amount'] - $reserved,
                    (int) $current['credited'] - (int) $current['refunded'],
                );
                if ($amount <= 0) {
                    return null;
                }
                self::units($amount);
                $key = 'refund-' . Db::id();
                Db::run('UPDATE cashier_trades SET refund_id=?,refund_amount=? WHERE id=?', [
                    $key,
                    $amount,
                    $t['id'],
                ]);
                return [
                    'action' => 'refund',
                    'request_id' => $key,
                    'order_id' => $current['remote_id'],
                    'amount' => self::units($amount),
                ];
            });
            if ($refund) {
                self::request($c, $refund);
                self::refresh($t);
            }
        }
    }
}
