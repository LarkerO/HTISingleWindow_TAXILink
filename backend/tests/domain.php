<?php
declare(strict_types=1);
putenv('DB_DSN=' . (getenv('TAXILINK_TEST_DB_DSN') ?: 'sqlite::memory:'));
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
$mapRequest = static function (
    string $method,
    string $path,
    ?string $token = null,
    array $input = [],
    ?string $origin = null,
) {
    $raw = "$method $path HTTP/1.1\r\nHost: localhost\r\n";
    if ($origin !== null) {
        $raw .= "Origin: $origin\r\n";
    }
    $body = $method === 'POST' ? Db::json($input) : '';
    if ($token !== null) {
        $csrf = Db::one('SELECT csrf FROM sessions WHERE id=?', [hash('sha256', $token)])['csrf'];
        $raw .= "Cookie: tl_session=$token\r\nX-CSRF-Token: $csrf\r\n";
    }
    if ($body !== '') {
        $raw .= "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
    }
    return (new \app\controller\ApiController())->handle(
        new \support\Request($raw . "\r\n" . $body),
    );
};
$mapSettings = ['mapId' => 'heishan7_0', 'allow3d' => false];
Db::insert('points', [
    'id' => 'search-case',
    'name' => 'Airport TAXI',
    'description' => 'Terminal ONE',
    'enabled' => 1,
]);
$search = Db::data($mapRequest('GET', '/api/points?q=airport')->rawBody());
check(
    count($search['items']) === 1 && $search['items'][0]['id'] === 'search-case',
    '站点名称搜索不区分英文大小写',
);
$search = Db::data($mapRequest('GET', '/api/points?q=terminal')->rawBody());
check(
    count($search['items']) === 1 && $search['items'][0]['id'] === 'search-case',
    '站点描述搜索不区分英文大小写',
);
Db::run('DELETE FROM points WHERE id=?', ['search-case']);
$publicMap = '/api/map-settings';
$adminMap = '/api/admin/map-settings';
check(
    Db::data($mapRequest('GET', $publicMap)->rawBody()) === ['mapId' => null, 'allow3d' => true],
    'BlueMap 公开接口提供默认设置',
);
$mapUser = Auth::login(['sub' => 'map-user'], 'map-admin');
$mapAdmin = Auth::login(['sub' => 'map-admin'], 'map-admin');
check(
    $mapRequest('POST', $adminMap, $mapUser, $mapSettings)->getStatusCode() === 403,
    '普通账号不能修改底图',
);
check(
    $mapRequest('POST', $adminMap, $mapAdmin, [
        'mapId' => '../other',
        'allow3d' => true,
    ])->getStatusCode() === 422,
    'BlueMap 拒绝非法地图 ID',
);
check(
    $mapRequest('POST', $adminMap, $mapAdmin, $mapSettings)->getStatusCode() === 200 &&
        Db::data($mapRequest('GET', $publicMap)->rawBody()) === $mapSettings,
    '平台管理员保存后两端可读取 BlueMap 设置',
);
$mapSettings['allow3d'] = true;
check(
    $mapRequest('POST', $adminMap, $mapAdmin, $mapSettings)->getStatusCode() === 200 &&
        Db::data($mapRequest('GET', $publicMap)->rawBody()) === $mapSettings,
    'BlueMap 设置支持重复更新',
);
// Content management access and visibility are enforced on the server.
$announcement = [
    'title' => '出行公告',
    'markdown' => '# 服务调整\n\n请提前规划出行。',
    'placements' => ['booking'],
    'published' => true,
    'listed' => false,
    'login_required' => false,
];
check(
    $mapRequest('POST', '/api/admin/announcements', $mapUser, $announcement)->getStatusCode() ===
        403,
    '普通用户不能编写公告',
);
$created = $mapRequest('POST', '/api/admin/announcements', $mapAdmin, $announcement);
check($created->getStatusCode() === 200, '平台管理员创建公告');
$announcementId = Db::data($created->rawBody())['id'];
check(
    count(
        Db::data($mapRequest('GET', '/api/announcements?placement=booking')->rawBody())['items'],
    ) === 1 && count(Db::data($mapRequest('GET', '/api/announcements')->rawBody())['items']) === 0,
    '通知栏位置独立于列表可见性',
);
check(
    !isset(
        Db::data($mapRequest('GET', '/api/announcements?placement=booking')->rawBody())['items'][0][
            'markdown'
        ],
    ),
    '列表不返回公告正文',
);
check(
    $mapRequest('GET', '/api/announcements/' . $announcementId)->getStatusCode() === 200,
    '隐藏列表的公告仍可从通知栏查看详情',
);
$restricted = \app\domain\Announcements::save(
    Db::one('SELECT * FROM users WHERE platform_admin=1'),
    [...$announcement, 'listed' => true, 'login_required' => true],
    $announcementId,
);
check(
    $mapRequest('GET', '/api/announcements/' . $announcementId)->getStatusCode() === 401 &&
        count(
            Db::data($mapRequest('GET', '/api/announcements?placement=booking')->rawBody())[
                'items'
            ],
        ) === 0,
    '登录限制在列表和详情一致执行',
);
check(
    $mapRequest('GET', '/api/announcements/' . $announcementId, $mapUser)->getStatusCode() ===
        200 &&
        count(Db::data($mapRequest('GET', '/api/announcements', $mapUser)->rawBody())['items']) ===
            1,
    '登录乘客可查看受限公告',
);
\app\domain\Announcements::save(
    Db::one('SELECT * FROM users WHERE platform_admin=1'),
    [...$announcement, 'published' => false],
    $announcementId,
);
check(
    $mapRequest('GET', '/api/announcements/' . $announcementId, $mapAdmin)->getStatusCode() === 404,
    '下架立即阻止乘客访问',
);
check(
    $mapRequest('POST', '/api/admin/announcements', $mapAdmin, [
        ...$announcement,
        'placements' => ['invalid'],
    ])->getStatusCode() === 422,
    '公告拒绝未知展示位置',
);
check(
    $mapRequest('POST', '/api/admin/announcements', $mapAdmin, [
        ...$announcement,
        'placements' => [['booking']],
    ])->getStatusCode() === 422,
    '公告拒绝嵌套位置参数',
);
check(
    $mapRequest('GET', '/api/admin/media/settings', $mapUser)->getStatusCode() === 403,
    'COS 设置仅平台管理员可见',
);
check(
    !str_contains($mapRequest('GET', '/api/admin/media/settings', $mapAdmin)->rawBody(), 'SECRET'),
    'COS 设置不暴露密钥',
);
rejects(
    fn() => \app\domain\Media::imageUrl('javascript:alert(1)'),
    'VALIDATION',
    '图片字段拒绝脚本地址',
);
rejects(
    fn() => \app\domain\Media::upload(
        Db::one('SELECT * FROM users WHERE portal_sub=?', ['map-user']),
        'vehicle',
        'other-company',
        null,
    ),
    'FORBIDDEN',
    '图片上传检查企业权限',
);
$uploadPath = tempnam(sys_get_temp_dir(), 'taxilink-media-');
file_put_contents($uploadPath, '<svg onload="alert(1)"></svg>');
$uploadFile = new \Webman\Http\UploadFile($uploadPath, 'image.png', 'image/png', UPLOAD_ERR_OK);
$mediaAdmin = Db::one('SELECT * FROM users WHERE platform_admin=1');
rejects(
    fn() => \app\domain\Media::upload($mediaAdmin, 'announcement', '', $uploadFile),
    'VALIDATION',
    '服务端拒绝伪装成 PNG 的 SVG',
);
file_put_contents(
    $uploadPath,
    base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMfoAAAAASUVORK5CYII=',
    ),
);
if (!\app\domain\Media::settings()['configured']) {
    rejects(
        fn() => \app\domain\Media::upload($mediaAdmin, 'company', '', $uploadFile),
        'COS_NOT_CONFIGURED',
        '未配置 COS 时上传明确返回配置错误',
    );
}
unlink($uploadPath);
Db::run('UPDATE companies SET image_url=? WHERE id=?', [
    'https://images.example/company.png',
    'jade',
]);
Db::run('UPDATE vehicles SET image_url=? WHERE company_id=?', [
    'https://images.example/car.png',
    'jade',
]);
$imageQuote = Booking::quote($u, [
    'pickupId' => 'riverside',
    'dropoffId' => 'museum',
    'passengers' => 2,
]);
$imageOffer = array_values(
    array_filter($imageQuote['offers'], fn($offer) => $offer['companyId'] === 'jade'),
)[0];
check(
    $imageOffer['companyImageUrl'] === 'https://images.example/company.png' &&
        $imageOffer['vehicleImageUrl'] === 'https://images.example/car.png',
    '车型和公司图片进入报价',
);
$imageOrder = Booking::create(
    $u,
    ['quoteId' => $imageQuote['id'], 'companyIds' => ['jade']],
    'image-snapshot-test',
);
Db::run('UPDATE vehicles SET image_url=? WHERE company_id=?', [
    'https://images.example/new.png',
    'jade',
]);
check(
    $imageOrder['offers'][0]['vehicleImageUrl'] === 'https://images.example/car.png',
    '订单保留接单时图片快照',
);
Db::run('DELETE FROM candidates WHERE order_id=?', [$imageOrder['id']]);
Db::run('DELETE FROM orders WHERE id=?', [$imageOrder['id']]);
Db::migrate();
check(
    Db::one('SELECT id FROM announcements WHERE id=?', [$announcementId]) !== null,
    '重复迁移保留公告数据',
);
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
$response = (new \app\controller\ApiController())->handle($request);
check($response->getStatusCode() === 200, '管理员可主动关闭模组重启回收订单');
$closed = Db::one('SELECT * FROM orders WHERE id=?', [$p['id']]);
check(
    $closed['state'] === 'FAILED' && (int) $closed['paid'] === 0 && (int) $closed['amount'] === 0,
    '重启回收订单关闭后停止重试并生成演示退款',
);
$earlyId = Db::id();
Db::insert(
    'orders',
    array_replace($settled, [
        'id' => $earlyId,
        'idempotency_key' => 'early-settlement-fixture',
        'state' => 'ACTIVE',
        'telemetry' => '{}',
        'billing_started' => $started,
        'billing_ended' => null,
        'paid' => 20000,
    ]),
);
Dispatch::ingest($earlyId, 'passenger.boarded', [], $started * 1000, 'early-boarded');
$dropoff = [
    'phase' => 'ACTIVE',
    'stage' => 'WAITING_DROPOFF',
    'onboardCount' => 1,
    'position' => ['x' => 5, 'z' => 0],
    'geometry' => [
        'progressMeters' => 50,
        'totalMeters' => 80,
        'legs' => [
            ['stage' => 'ON_TRIP', 'startMeters' => 10, 'endMeters' => 50],
            ['stage' => 'RETURNING', 'startMeters' => 50, 'endMeters' => 80],
        ],
        'points' => [[0, 0, 64, 0], [40, 4, 64, 0], [60, 6, 64, 0], [80, 8, 64, 0]],
    ],
];
$arrival = ($started + 60) * 1000;
Dispatch::ingest($earlyId, 'reconcile.final', $dropoff, $arrival);
check(
    Db::one('SELECT state FROM orders WHERE id=?', [$earlyId])['state'] === 'ACTIVE',
    '抵达时仍有人则继续等待下车',
);
$dropoff['onboardCount'] = 0;
Dispatch::ingest($earlyId, 'reconcile.final', $dropoff, $arrival + 1000);
$early = Db::one('SELECT * FROM orders WHERE id=?', [$earlyId]);
check(
    $early['state'] === 'COMPLETED' && (int) $early['vehicle_pending'] === 1,
    '全部下车后立即结束乘客订单并继续跟踪车辆',
);
check(
    (int) $early['billing_ended'] === $started + 60 &&
        (int) $early['paid'] === (int) $early['amount'],
    '下车时按抵达时间结算并退款',
);
$returning = array_replace($dropoff, ['stage' => 'RETURNING', 'stageLabel' => '回库中']);
Dispatch::ingest($earlyId, 'taxi.telemetry', $returning, $arrival + 2000);
$during = Db::one('SELECT * FROM orders WHERE id=?', [$earlyId]);
$passenger = Booking::passengerView($during)['telemetry'];
check(
    Booking::view($during)['telemetry']['stage'] === 'RETURNING' &&
        $passenger['stage'] === 'COMPLETED' &&
        !isset($passenger['position']) &&
        count($passenger['geometry']['legs']) === 1 &&
        end($passenger['geometry']['points'])[0] === 50,
    '运营端可见回库，乘客地图在下车点截断并隐藏车辆',
);
Dispatch::ingest(
    $earlyId,
    'reconcile.final',
    ['phase' => 'FAILED', 'stage' => 'FAILED'],
    $arrival + 3000,
);
$after = Db::one('SELECT * FROM orders WHERE id=?', [$earlyId]);
check(
    $after['state'] === 'COMPLETED' &&
        (int) $after['vehicle_pending'] === 0 &&
        $after['amount'] === $early['amount'] &&
        $after['paid'] === $early['paid'] &&
        count(Db::all('SELECT id FROM payments WHERE order_id=?', [$earlyId])) === 1,
    '回库失败只结束车辆跟踪，已结算订单和退款保持不变',
);
check(
    $mapRequest('GET', '/api/admin/cashier', $mapUser)->getStatusCode() === 403,
    '普通乘客不能读取总商户配置',
);
check(
    $mapRequest('POST', '/api/admin/cashier', $mapUser, ['enabled' => false])->getStatusCode() ===
        403,
    '普通乘客不能修改商户配置',
);
check(
    $mapRequest('GET', '/api/admin/cashier/jade', $mapUser)->getStatusCode() === 403,
    '非企业管理员不能读取公司商户配置',
);
$cashierSave = $mapRequest('POST', '/api/admin/cashier', $mapAdmin, [
    'enabled' => false,
    'privateKey' => 'test-private',
    'keyId' => 'test-api-key',
]);
$cashierView = Db::data($cashierSave->rawBody());
check(
    $cashierSave->getStatusCode() === 200 &&
        !isset($cashierView['privateKey'], $cashierView['keyId']) &&
        $cashierView['privateKeyConfigured'],
    '管理 API 保存商户但不返回私钥与 API Key',
);
$cashierRead = Db::data($mapRequest('GET', '/api/admin/cashier', $mapAdmin)->rawBody());
check(!isset($cashierRead['privateKey'], $cashierRead['keyId']), '管理 API 读取配置不泄漏密钥');
// Browser entry points and encrypted COS settings must upgrade without replacing identities.
$clientPath = '/api/admin/passenger-clients';
check(
    $mapRequest('POST', $clientPath, $mapUser, ['origins' => []])->getStatusCode() === 403,
    '普通用户不能修改乘客端白名单',
);
foreach (
    [
        'https://evil.test/path',
        'https://evil.test/',
        'https://u:p@evil.test',
        'http://evil.test',
        'https://*.test',
        'null',
    ]
    as $invalid
) {
    check(
        $mapRequest('POST', $clientPath, $mapAdmin, ['origins' => [$invalid]])->getStatusCode() ===
            422,
        '乘客端拒绝非法源地址 ' . $invalid,
    );
}
$clients = ['https://v2.taxi.test', 'https://v3.taxi.test'];
check(
    $mapRequest('POST', $clientPath, $mapAdmin, ['origins' => $clients])->getStatusCode() === 200,
    '管理员可登记多个乘客端',
);
foreach ($clients as $origin) {
    check(\app\domain\Clients::allowed($origin), 'HTTP 与 WebSocket 共用登记来源 ' . $origin);
    check(
        $mapRequest(
            'POST',
            '/api/quotes',
            $mapUser,
            ['pickupId' => 'riverside', 'dropoffId' => 'museum', 'passengers' => 1],
            $origin,
        )->getStatusCode() === 200,
        '登记乘客端可以请求报价',
    );
    check(
        Auth::oauthRedirect('passenger', $origin) === $origin . '/api/auth/callback',
        '乘客端使用各自 OAuth 回调',
    );
}
check(
    \app\domain\Clients::allowed(Env::get('APP_ORIGIN', 'http://localhost:5173')),
    '新增乘客端保留原入口',
);
check(
    $mapRequest('POST', '/api/quotes', $mapUser, [], 'https://evil.test')->getStatusCode() === 403,
    '未知乘客端即使拥有 CSRF 也被拒绝',
);
Db::migrate();
check(\app\domain\Clients::allowed($clients[0]), '重复迁移保留乘客端接入设置');
class PassengerTestConnection extends \Workerman\Connection\TcpConnection
{
    public bool $closed = false;
    public function __construct(int $id)
    {
        $this->id = $id;
    }
    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->closed = true;
    }
    public function __destruct() {}
}
$stream = new \app\process\PassengerStream();
foreach ([$clients[0], $clients[1], 'https://evil.test'] as $index => $origin) {
    $connection = new PassengerTestConnection(9000 + $index);
    $stream->onWebSocketConnect(
        $connection,
        new \Workerman\Protocols\Http\Request(
            "GET /ws HTTP/1.1\r\nHost: localhost\r\nOrigin: $origin\r\nCookie: tl_session=$mapUser\r\n\r\n",
        ),
    );
    check(
        $connection->closed === ($origin === 'https://evil.test'),
        'WebSocket 握手按多端白名单校验 ' . $origin,
    );
}
$anonymousStream = new PassengerTestConnection(9010);
$stream->onWebSocketConnect(
    $anonymousStream,
    new \Workerman\Protocols\Http\Request(
        "GET /ws HTTP/1.1\r\nHost: localhost\r\nOrigin: {$clients[0]}\r\n\r\n",
    ),
);
check($anonymousStream->closed, '登记来源的 WebSocket 仍需要有效会话');
$beforeUpgrade = [];
foreach (['users', 'orders', 'payments', 'cashier_trades'] as $table) {
    $beforeUpgrade[$table] = Db::all("SELECT * FROM $table ORDER BY id");
}
Db::migrate();
foreach ($beforeUpgrade as $table => $records) {
    check(
        Db::all("SELECT * FROM $table ORDER BY id") === $records,
        '重复升级完整保留业务数据 ' . $table,
    );
}

$mediaPath = '/api/admin/media/settings';
$mediaInput = [
    'bucket' => 'images-1234567890',
    'region' => 'ap-guangzhou',
    'publicUrl' => 'https://cdn.test/taxi',
    'secretId' => 'AKIDTEST123456789',
    'secretKey' => 'SecretKeyTest123456789',
];
check(
    $mapRequest('POST', $mediaPath, $mapUser, $mediaInput)->getStatusCode() === 403,
    '普通用户不能写入 COS 凭据',
);
$keyFile = sys_get_temp_dir() . '/taxilink-settings-' . bin2hex(random_bytes(8)) . '.key';
putenv('SETTINGS_KEY_FILE=' . $keyFile);
try {
    $saved = $mapRequest('POST', $mediaPath, $mapAdmin, $mediaInput);
    check(
        $saved->getStatusCode() === 200 && Db::data($saved->rawBody())['configured'],
        '管理员保存 COS 后即时生效',
    );
    $stored = Db::one('SELECT config FROM system_settings WHERE id=?', ['cos'])['config'];
    check(
        !str_contains($stored, $mediaInput['secretKey']) &&
            !str_contains($stored, $mediaInput['secretId']),
        '数据库加密保存 COS 凭据',
    );
    check(
        !str_contains($saved->rawBody(), $mediaInput['secretKey']) &&
            !str_contains($saved->rawBody(), $mediaInput['secretId']),
        'COS 写入响应不回显凭据',
    );
    $updated = $mapRequest('POST', $mediaPath, $mapAdmin, [
        ...$mediaInput,
        'secretId' => '',
        'secretKey' => '',
        'region' => 'ap-shanghai',
    ]);
    $opened = \app\domain\SettingsSecret::open(
        Db::one('SELECT config FROM system_settings WHERE id=?', ['cos'])['config'],
    );
    check(
        $updated->getStatusCode() === 200 &&
            $opened['secretKey'] === $mediaInput['secretKey'] &&
            $opened['region'] === 'ap-shanghai',
        'COS 空白密钥保留旧值且其他设置立即更新',
    );
    check(
        !str_contains(
            Db::json(Db::all("SELECT data FROM audit_logs WHERE action='media.settings'")),
            $mediaInput['secretKey'],
        ),
        '审计日志不包含 COS 密钥',
    );
    check(
        $mapRequest('POST', $mediaPath, $mapAdmin, [
            ...$mediaInput,
            'publicUrl' => 'http://cdn.test',
        ])->getStatusCode() === 422,
        'COS 公开域名必须使用 HTTPS',
    );
    Db::migrate();
    check(
        \app\domain\Media::settings()['region'] === 'ap-shanghai',
        '重复迁移保留 COS 设置及加密内容',
    );
} finally {
    Db::run('DELETE FROM system_settings WHERE id=?', ['cos']);
    if (is_file($keyFile)) {
        unlink($keyFile);
    }
    putenv('SETTINGS_KEY_FILE');
}
echo "\n$checks 项领域验证通过\n";
