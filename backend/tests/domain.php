<?php
declare(strict_types=1);
putenv('DB_DSN=sqlite::memory:');
putenv('DEMO_MODE=1');
putenv('APP_ENV=test');
require dirname(__DIR__) . '/vendor/autoload.php';
use app\domain\{Admin, AppError, Auth, Booking, Db, Demo, Dispatch, Env, Fare};
$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    $checks++;
    if (!$value) {
        throw new RuntimeException($message);
    }
    echo "✓ $message\n";
}
function rejects(callable $f, string $key, string $message): void
{
    try {
        $f();
    } catch (AppError $e) {
        check($e->key === $key, $message);
        return;
    }
    throw new RuntimeException($message);
}
Db::migrate();
Demo::seed();
$u = Db::one('SELECT * FROM users WHERE id=?', ['demo-user']);
$q = Booking::quote($u, ['pickupId' => 'riverside', 'dropoffId' => 'museum', 'passengers' => 2]);
check(count($q['offers']) === 2, '同一路线可得到两个企业报价');
check(
    $q['offers'][0]['route']['pickupDistanceMeters'] <
        $q['offers'][1]['route']['pickupDistanceMeters'],
    '报价按轨道接客距离排序',
);
rejects(
    fn() => Booking::quote($u, [
        'pickupId' => 'airport',
        'dropoffId' => 'museum',
        'passengers' => 1,
    ]),
    'POINT_REQUIRED',
    '虚拟点组不能直接下单',
);
$input = ['quoteId' => $q['id'], 'companyIds' => ['jade', 'river']];
$o = Booking::create($u, $input, 'test-order-first');
$retry = Booking::create($u, $input, 'test-order-first');
check($o['id'] === $retry['id'], '相同幂等键重试返回同一订单');
rejects(
    fn() => Booking::create(
        $u,
        ['quoteId' => $q['id'], 'companyIds' => ['jade']],
        'test-order-first',
    ),
    'ORDER_CONFLICT',
    '相同幂等键不同参数被拒绝',
);
$o2 = Booking::create($u, $input, 'test-order-second');
$o3 = Booking::create($u, $input, 'test-order-third');
Dispatch::tick();
$one = Db::one('SELECT * FROM orders WHERE id=?', [$o['id']]);
$two = Db::one('SELECT * FROM orders WHERE id=?', [$o2['id']]);
$three = Db::one('SELECT * FROM orders WHERE id=?', [$o3['id']]);
check(
    $one['company_id'] === 'jade' && $two['company_id'] === 'river',
    '近端车厂繁忙时尝试下一个企业',
);
check($three['state'] === 'QUEUED', '所有中心无车时保留全部候补');
check(
    (int) Db::one('SELECT COUNT(*) AS n FROM demo_rides')['n'] === 2,
    '多企业候补不会重复发出车辆',
);
check(
    (int) Db::one("SELECT COUNT(*) AS n FROM candidates WHERE order_id=? AND state='CANCELLED'", [
        $one['id'],
    ])['n'] === 1,
    '成功派车后撤销其余候补',
);
Demo::command('taxi.destroy', ['orderId' => $one['id']]);
Dispatch::tick();
check(
    Db::one('SELECT state FROM orders WHERE id=?', [$three['id']])['state'] === 'ACTIVE',
    '任一中心释放后候补订单成功派车',
);
$fare = Fare::defaults();
$fare['pickupFreeMeters'] = 1000;
$fare['pickupPerKmMinor'] = 100;
$fare['returnFreeMeters'] = 2000;
$fare['returnPerKmMinor'] = 200;
$calculated = Fare::calculate($fare, 2000, 0, 1500, 2500);
check(
    $calculated['components']['pickupMinor'] === 50 &&
        $calculated['components']['returnMinor'] === 100,
    '接客回库分别计算超程调度费',
);
rejects(fn() => Fare::validate(['baseMinor' => -1]), 'INVALID_FARE', '负数费率被拒绝');
$fare['payment'] = 'adjust';
Db::run('UPDATE companies SET fare=? WHERE id=?', [Db::json($fare), 'jade']);
$pre = Booking::quote($u, ['pickupId' => 'riverside', 'dropoffId' => 'museum', 'passengers' => 1]);
$p = Booking::create($u, ['quoteId' => $pre['id'], 'companyIds' => ['jade']], 'test-prepayment');
check($p['state'] === 'AWAITING_PAYMENT', '预付订单付款前不参与派车');
$paid = Booking::pay($u, $p['id']);
check(
    $paid['state'] === 'QUEUED' && $paid['paidMinor'] === $paid['amountMinor'],
    '演示预付后订单加入候补',
);
rejects(fn() => Booking::pay($u, $p['id']), 'PAYMENT_NOT_DUE', '重复付款不会产生第二次扣款记录');
Db::tx(
    fn() => Dispatch::finish(
        Db::one('SELECT * FROM orders WHERE id=?', [$p['id']]),
        'CANCELLED',
        'test.cancel',
        0,
    ),
);
check(
    (int) Db::one('SELECT paid FROM orders WHERE id=?', [$p['id']])['paid'] === 0,
    '预付候补取消后生成全额演示退款',
);
check(
    (int) Db::one("SELECT COUNT(*) AS n FROM payments WHERE order_id=? AND kind='DEMO_REFUND'", [
        $p['id'],
    ])['n'] === 1,
    '取消退款仅记录一次',
);
$candidate = Db::one('SELECT * FROM candidates WHERE id=?', [$two['candidate_id']]);
$offer = Db::data($candidate['offer']);
$offer['rules']['mode'] = 'metered';
$offer['rules']['perMinuteMinor'] = 60;
Db::run('UPDATE candidates SET offer=? WHERE id=?', [Db::json($offer), $candidate['id']]);
$started = time() - 120;
Dispatch::ingest($two['id'], 'ride.pickup_departure_released', [], $started * 1000, 'depart-1');
Dispatch::ingest(
    $two['id'],
    'ride.pickup_departure_released',
    [],
    ($started + 5) * 1000,
    'depart-1',
);
check(
    (int) Db::one('SELECT billing_started FROM orders WHERE id=?', [$two['id']])[
        'billing_started'
    ] === $started,
    '自动发车事件开始计费且重放不重复启动',
);
Dispatch::ingest(
    $two['id'],
    'taxi.telemetry',
    [
        'phase' => 'ACTIVE',
        'stage' => 'WAITING_DROPOFF',
        'geometry' => [
            'progressMeters' => 5050,
            'legs' => [['stage' => 'ON_TRIP', 'startMeters' => 850, 'endMeters' => 5050]],
        ],
    ],
    ($started + 60) * 1000,
);
$ending = Db::one('SELECT * FROM orders WHERE id=?', [$two['id']]);
check((int) $ending['billing_ended'] === $started + 60, '到达下车点即停止乘客时长计费');
$previousAmount = $ending['amount'];
Dispatch::ingest(
    $two['id'],
    'taxi.telemetry',
    ['phase' => 'ACTIVE', 'stage' => 'RETURNING'],
    time() * 1000,
);
check(
    Db::one('SELECT amount FROM orders WHERE id=?', [$two['id']])['amount'] === $previousAmount,
    '回库时间不继续计入乘客行程',
);
Dispatch::ingest(
    $two['id'],
    'ride.completed',
    ['phase' => 'COMPLETED', 'stage' => 'RETURNING'],
    time() * 1000,
);
check(
    Db::one('SELECT state FROM orders WHERE id=?', [$two['id']])['state'] === 'FINALIZING',
    '终态通知先进入核对阶段，不提前结算退款',
);
Dispatch::ingest($two['id'], 'passenger.boarded', [], ($started - 5) * 1000, 'historical-board');
Dispatch::ingest(
    $two['id'],
    'reconcile.final',
    ['phase' => 'COMPLETED', 'stage' => 'RETURNING'],
    time() * 1000,
);
$settled = Db::one('SELECT * FROM orders WHERE id=?', [$two['id']]);
check(
    $settled['state'] === 'COMPLETED' && (int) $settled['amount'] > 0,
    '补回历史登车记录后按实际行程结算',
);
Dispatch::ingest($two['id'], 'ride.accepted', ['phase' => 'GENERATING'], time() * 1000);
check(
    Db::one('SELECT state FROM orders WHERE id=?', [$two['id']])['state'] === 'COMPLETED',
    '迟到事件不会使已结算订单重新进入行程',
);
Auth::login([
    'sub' => 'unprivileged-portal-owner',
    'preferred_username' => '普通用户',
    'role' => 'OWNER',
]);
$normal = Db::one('SELECT * FROM users WHERE portal_sub=?', ['unprivileged-portal-owner']);
check(!(bool) $normal['platform_admin'], 'Portal 全局角色不会自动授予 TAXILink 管理权限');
rejects(fn() => Auth::company($normal, 'jade'), 'FORBIDDEN', '无企业成员身份不能访问企业');
$invite = Admin::invite($u, 'jade', 'dispatcher');
parse_str(parse_url($invite['url'], PHP_URL_QUERY), $args);
Admin::accept($normal, $args['invite']);
check(
    count(Db::all('SELECT * FROM memberships WHERE user_id=?', [$normal['id']])) === 1,
    '邀请由登录账号主动接受',
);
rejects(
    fn() => Admin::accept($normal, $args['invite']),
    'INVITATION_INVALID',
    '一次性邀请不能重复领取',
);
rejects(fn() => Auth::company($normal, 'jade', true), 'FORBIDDEN', '调度员不能修改企业配置');
Admin::scope($u, 'river', ['action' => 'remove', 'groupIds' => ['airport'], 'pointIds' => []]);
check(
    count(
        Db::all(
            "SELECT * FROM company_points WHERE company_id='river' AND point_id LIKE 'airport-%'",
        ),
    ) === 0,
    '点组可以批量移除当前子点',
);
// Cross-component regression cases use isolated in-memory order records.
foreach (['demo_rides', 'payments', 'events', 'candidates', 'orders', 'quotes'] as $table) {
    Db::run("DELETE FROM $table");
}
$q = Booking::quote($u, ['pickupId' => 'riverside', 'dropoffId' => 'museum', 'passengers' => 1]);
$p = Booking::create($u, ['quoteId' => $q['id'], 'companyIds' => ['jade']], 'delayed-prepayment');
Db::run('UPDATE orders SET queue_until=? WHERE id=?', [time() - 60, $p['id']]);
$paid = Booking::pay($u, $p['id']);
check($paid['queueUntil'] > time() + 200, '付款后重新开始完整候补窗口');
Dispatch::tick();
$future = (time() + 10) * 1000;
Dispatch::ingest($p['id'], 'taxi.telemetry', ['phase' => 'ACTIVE', 'stage' => 'ON_TRIP'], $future);
$before = Booking::view(Db::one('SELECT * FROM orders WHERE id=?', [$p['id']]));
Dispatch::ingest(
    $p['id'],
    'ride.waiting_pickup',
    ['stage' => 'WAITING_PICKUP'],
    $future - 5000,
    'review-old-log',
);
Dispatch::ingest(
    $p['id'],
    'taxi.telemetry',
    ['phase' => 'ACTIVE', 'stage' => 'TO_PICKUP'],
    $future - 1000,
);
$after = Booking::view(Db::one('SELECT * FROM orders WHERE id=?', [$p['id']]));
check($after['telemetry']['stage'] === 'ON_TRIP', '历史日志和迟到遥测不会覆盖最新行程阶段');
check($after['revision'] > $before['revision'], '订单提供单调版本号用于 HTTP 与 WebSocket 去旧');
Dispatch::ingest($p['id'], 'ride.completed', ['phase' => 'COMPLETED'], $future + 1000);
Dispatch::ingest($p['id'], 'passenger.boarded', [], $future - 10000, 'review-board');
check(
    Db::one('SELECT state FROM orders WHERE id=?', [$p['id']])['state'] === 'FINALIZING',
    '日志回放不会把待结算订单变回活动状态',
);

// Disable a scope after quoting: the old quote must not authorize a new dispatch.
$q = Booking::quote($u, ['pickupId' => 'riverside', 'dropoffId' => 'museum', 'passengers' => 1]);
$revoked = Booking::create(
    $u,
    ['quoteId' => $q['id'], 'companyIds' => ['river']],
    'revoked-scope-order',
);
Admin::scope($u, 'river', ['action' => 'remove', 'pointIds' => ['museum']]);
Dispatch::tick();
Dispatch::tick();
check(
    Db::one('SELECT state FROM orders WHERE id=?', [$revoked['id']])['state'] === 'FAILED',
    '撤销运营范围后旧报价不会继续派车',
);
check(
    !Db::one('SELECT id FROM demo_rides WHERE id=?', [$revoked['id']]),
    '范围撤销没有产生游戏车辆',
);

$vehicle = Db::one('SELECT * FROM vehicles WHERE company_id=?', ['jade']);
$config = Db::data($vehicle['config']);
$config['maxSpeedKmh'] = '30';
$config['vehicleCars'][0]['length'] = '4';
$config['centerId'] = 'unauthorized-center';
$config['pickupPlatformId'] = '999';
$saved = Admin::save($u, 'vehicles', [
    'companyId' => 'jade',
    'name' => '协议规范化测试',
    'config' => $config,
]);
$clean = Db::data($saved['config']);
check(
    is_numeric($clean['maxSpeedKmh']) &&
        !is_string($clean['maxSpeedKmh']) &&
        !is_string($clean['vehicleCars'][0]['length']),
    '管理端数值字符串转换为模组要求的 JSON 数字',
);
check(
    !isset($clean['centerId']) && !isset($clean['pickupPlatformId']),
    '车型配置不能夹带路线或中心覆盖参数',
);
putenv('ADMIN_ORIGIN=https://taxi-admin.example.com');
putenv('PORTAL_ADMIN_REDIRECT_URI');
check(
    Auth::oauthRedirect('admin') === 'https://taxi-admin.example.com/api/auth/callback',
    '管理端 OAuth 回到管理端域名设置 Cookie',
);
check(
    Auth::returnPath('/profile?invite=abc') === '/profile?invite=abc',
    'OAuth 保留邀请页面上下文',
);
check(
    Auth::returnPath('//evil.example') === '/' && Auth::returnPath('/\\evil.example') === '/',
    'OAuth 返回路径拒绝外域重定向',
);
putenv('TAXILINK_PATH_TEST=C:\\keys\\mod.pem');
check(
    Env::keyPath('TAXILINK_PATH_TEST', '') === 'C:\\keys\\mod.pem',
    'Windows 绝对密钥路径不重复拼接项目目录',
);
putenv('TAXILINK_PATH_TEST=/srv/keys/mod.pem');
check(Env::keyPath('TAXILINK_PATH_TEST', '') === '/srv/keys/mod.pem', 'Linux 绝对密钥路径保持原样');

$loginToken = Auth::login(['sub' => 'demo', 'preferred_username' => '测试管理员']);
$session = Db::one('SELECT * FROM sessions WHERE id=?', [hash('sha256', $loginToken)]);
Db::run("UPDATE orders SET state='NEEDS_ATTENTION',telemetry=?,paid=500,amount=500 WHERE id=?", [
    Db::json(['phase' => 'RECOVERED']),
    $p['id'],
]);
$body = '{"action":"cancel"}';
$request = new \support\Request(
    "POST /api/admin/orders/{$p['id']}/control HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nCookie: tl_session=$loginToken\r\nX-CSRF-Token: {$session['csrf']}\r\nContent-Length: " .
        strlen($body) .
        "\r\n\r\n" .
        $body,
);
$response = new \app\controller\ApiController()->handle($request);
check($response->getStatusCode() === 200, '管理员可主动关闭模组重启回收订单');
$closed = Db::one('SELECT * FROM orders WHERE id=?', [$p['id']]);
check(
    $closed['state'] === 'FAILED' && (int) $closed['paid'] === 0 && (int) $closed['amount'] === 0,
    '重启回收订单关闭后停止重试并生成演示退款',
);
echo "\n$checks 项领域验证通过\n";
