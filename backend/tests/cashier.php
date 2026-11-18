<?php
declare(strict_types=1);
putenv('DB_DSN=sqlite::memory:');
putenv('DEMO_MODE=1');
putenv('APP_ENV=test');
require dirname(__DIR__) . '/vendor/autoload.php';
use app\domain\{AppError, Booking, Cashier, CashierCrypto, Db, Demo, Dispatch, Fare};
function check(bool $value, string $label): void
{
    if (!$value) {
        throw new RuntimeException($label);
    }
    echo "✓ $label\n";
}
Db::migrate();
Demo::seed();
$u = Db::one('SELECT * FROM users WHERE id=?', ['demo-user']);
$q = Booking::quote($u, ['pickupId' => 'riverside', 'dropoffId' => 'museum', 'passengers' => 1]);
$o = Booking::create($u, ['quoteId' => $q['id'], 'companyIds' => ['jade']], 'cashier-test');
Db::run("UPDATE orders SET state='AWAITING_PAYMENT',amount=600,paid=0,company_id=NULL WHERE id=?", [
    $o['id'],
]);
putenv('DEMO_MODE=0');
check(
    Fare::settlement(525) === 600 && Fare::settlement(600) === 600 && Fare::settlement(0) === 0,
    '整币向上取整且零元保持零元',
);
try {
    Booking::pay($u, $o['id']);
    throw new RuntimeException('未配置应拒绝');
} catch (AppError $e) {
    check($e->key === 'CASHIER_NOT_CONFIGURED', '正式环境未配置商户禁止演示入账');
}
$bin = getenv('CASHIER_OPENSSL') ?: 'openssl';
$merchant = CashierCrypto::generate($bin);
$platform = CashierCrypto::generate($bin);
$dir = sys_get_temp_dir() . '/taxilink-cashier-' . Db::id();
mkdir($dir, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$fixture = [
    'platformPrivate' => $platform['private'],
    'merchantPublic' => $merchant['public'],
    'state' => $dir . '/state.json',
];
file_put_contents($dir . '/config.json', Db::json($fixture));
putenv('CASHIER_TEST_CONFIG=' . $dir . '/config.json');
$process = proc_open(
    [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/cashier.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $dir . '/log', 'a'], 2 => ['file', $dir . '/log', 'a']],
    $pipes,
);
try {
    for ($i = 0; $i < 50; $i++) {
        $ready = @fsockopen('127.0.0.1', (int) substr(strrchr($address, ':'), 1));
        if ($ready) {
            fclose($ready);
            break;
        }
        usleep(100000);
    }
    $config = [
        'enabled' => true,
        'origin' => 'http://' . $address,
        'merchant' => str_repeat('1', 32),
        'environment' => 'sandbox',
        'keyId' => 'test-key',
        'privateKey' => $merchant['private'],
        'platformKey' => $platform['public'],
        'returnUrl' => 'https://taxi.example/payment-return',
    ];
    $safe = Cashier::save('default', $config);
    check(
        !isset($safe['privateKey'], $safe['keyId']) && $safe['privateKeyConfigured'],
        '配置响应不返回商户私钥及 API Key',
    );
    Cashier::save('jade', $config + []);
    $companyConfig = $config;
    $companyConfig['merchant'] = str_repeat('2', 32);
    Cashier::save('jade', $companyConfig);
    Db::run('UPDATE orders SET company_id=? WHERE id=?', ['jade', $o['id']]);
    $pay = Booking::pay($u, $o['id']);
    check(
        isset($pay['checkoutUrl']) &&
            $pay['paidMinor'] === 0 &&
            $pay['state'] === 'AWAITING_PAYMENT',
        '跳转前未入账也未放行派车',
    );
    check(
        Db::data(Db::one('SELECT config FROM cashier_trades')['config'])['merchant'] ===
            str_repeat('2', 32),
        '承运公司专属商户优先',
    );
    $retry = Booking::pay($u, $o['id']);
    check(
        $retry['checkoutUrl'] === $pay['checkoutUrl'] &&
            count(Db::all('SELECT * FROM cashier_trades')) === 1,
        '重复点击复用同一收银台订单',
    );
    $state = Db::data(file_get_contents($fixture['state']));
    $remote = array_key_first($state['orders']);
    check($state['orders'][$remote]['amount'] === 6, '600 分对应 6 个收银台游戏币');
    $state['orders'][$remote]['status'] = 'paid';
    file_put_contents($fixture['state'], Db::json($state));
    Cashier::syncOrder($o['id']);
    Cashier::syncOrder($o['id']);
    $paid = Db::one('SELECT * FROM orders WHERE id=?', [$o['id']]);
    check(
        (int) $paid['paid'] === 600 &&
            $paid['state'] === 'QUEUED' &&
            count(Db::all('SELECT * FROM payments')) === 1,
        '签名到账确认仅入账一次并加入候补',
    );
    $changed = $companyConfig;
    $changed['merchant'] = str_repeat('3', 32);
    Cashier::save('jade', $changed);
    Dispatch::finish($paid, 'CANCELLED', 'test.cancelled', 225);
    $cancel = Db::one('SELECT * FROM orders WHERE id=?', [$o['id']]);
    check(
        (int) $cancel['amount'] === 300 && (int) $cancel['paid'] === 600,
        '取消费取整且未伪造本地退款',
    );
    Cashier::syncOrder($o['id']);
    Cashier::syncOrder($o['id']);
    $cancel = Db::one('SELECT * FROM orders WHERE id=?', [$o['id']]);
    check(
        (int) $cancel['paid'] === 300 && count(Db::all('SELECT * FROM payments')) === 2,
        '配置变更后仍按原商户退款且重试不重复退款',
    );
    $fallback = $cancel;
    $fallback['id'] = Db::id();
    $fallback['idempotency_key'] = Db::id();
    $fallback['company_id'] = 'river';
    $fallback['amount'] = 400;
    $fallback['paid'] = 0;
    $fallback['state'] = 'COMPLETED';
    Db::insert('orders', $fallback);
    Booking::pay($u, $fallback['id']);
    check(
        Db::data(
            Db::one('SELECT config FROM cashier_trades WHERE order_id=?', [$fallback['id']])[
                'config'
            ],
        )['merchant'] === str_repeat('1', 32),
        '公司未匹配专属商户时使用总商户',
    );
    $unknown = $fallback;
    $unknown['id'] = Db::id();
    $unknown['idempotency_key'] = Db::id();
    $unknown['company_id'] = null;
    $unknown['amount'] = 525;
    Db::insert('orders', $unknown);
    $legacyPay = Booking::pay($u, $unknown['id']);
    check($legacyPay['amountMinor'] === 600, '升级前小数订单在支付时按统一规则取整');
    check(
        Db::data(
            Db::one('SELECT config FROM cashier_trades WHERE order_id=?', [$unknown['id']])[
                'config'
            ],
        )['merchant'] === str_repeat('1', 32),
        '未确定承运公司的预付订单使用总商户',
    );
    $timeout = $fallback;
    $timeout['id'] = Db::id();
    $timeout['idempotency_key'] = Db::id();
    Db::insert('orders', $timeout);
    touch($fixture['state'] . '.drop');
    try {
        Booking::pay($u, $timeout['id']);
        throw new RuntimeException('断线未报告');
    } catch (AppError $e) {
        check($e->key === 'INVALID_ENVELOPE', '远端下单成功但响应断线时不误报到账');
    }
    $retryTimeout = Booking::pay($u, $timeout['id']);
    $state = Db::data(file_get_contents($fixture['state']));
    check(
        isset($retryTimeout['checkoutUrl']) &&
            count(
                array_filter(
                    $state['orders'],
                    fn($r) => $r['out_trade_no'] ===
                        Db::one('SELECT id FROM cashier_trades WHERE order_id=?', [$timeout['id']])[
                            'id'
                        ],
                ),
            ) === 1,
        '不确定下单结果复用原请求编号恢复且不重复创建',
    );
    $bad = CashierCrypto::seal($bin, $merchant['private'], $platform['public'], 'test', [
        'ok' => true,
    ]);
    $bad['data'] = base64_encode('tampered');
    try {
        CashierCrypto::open($bin, $bad, $platform['private'], $merchant['public'], 'test');
        throw new RuntimeException('篡改未拒绝');
    } catch (AppError $e) {
        check($e->key === 'INVALID_ENVELOPE', '签名报文篡改被拒绝');
    }
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($dir . '/*') as $file) {
        unlink($file);
    }
    rmdir($dir);
}
