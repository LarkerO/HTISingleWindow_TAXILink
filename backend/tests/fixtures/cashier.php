<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use app\domain\{CashierCrypto, Db};
$c = Db::data(file_get_contents(getenv('CASHIER_TEST_CONFIG')));
$input = CashierCrypto::open(
    getenv('CASHIER_OPENSSL'),
    Db::data(file_get_contents('php://input')),
    $c['platformPrivate'],
    $c['merchantPublic'],
    'merchant-request',
);
$state = is_file($c['state'])
    ? Db::data(file_get_contents($c['state']))
    : ['orders' => [], 'requests' => []];
if ($input['action'] === 'create') {
    $key = $input['request_id'];
    if (!isset($state['requests'][$key])) {
        $id = Db::id();
        $state['orders'][$id] = [
            'id' => $id,
            'out_trade_no' => $input['out_trade_no'],
            'merchant' => $input['merchant'],
            'env' => $input['environment'],
            'kind' => 'pay',
            'amount' => $input['amount'],
            'captured' => $input['amount'],
            'refunded' => 0,
            'expires' => time() + 1800,
            'status' => 'pending',
        ];
        $state['requests'][$key] = ['status' => 'completed', 'id' => $id];
    }
    $result = [
        'request' => $state['requests'][$key],
        'order' => $state['orders'][$state['requests'][$key]['id']],
    ];
} elseif ($input['action'] === 'refund') {
    $key = $input['request_id'];
    if (!isset($state['requests'][$key])) {
        $state['orders'][$input['order_id']]['refunded'] += $input['amount'];
        $state['requests'][$key] = ['status' => 'completed'];
    }
    $result = [
        'request' => $state['requests'][$key],
        'order' => $state['orders'][$input['order_id']],
    ];
} else {
    $result = $state['orders'][$input['order_id']];
}
file_put_contents($c['state'], Db::json($state));
if (is_file($c['state'] . '.drop')) {
    unlink($c['state'] . '.drop');
    exit();
}
$output = ['ok' => true, 'result' => $result];
foreach (['merchant', 'key_id', 'environment', 'challenge', 'request_id'] as $key) {
    if (isset($input[$key])) {
        $output[$key] = $input[$key];
    }
}
$output['timestamp'] = time();
header('Content-Type: application/json');
echo Db::json(
    CashierCrypto::seal(
        getenv('CASHIER_OPENSSL'),
        $c['platformPrivate'],
        $c['merchantPublic'],
        'merchant-response',
        $output,
    ),
);
