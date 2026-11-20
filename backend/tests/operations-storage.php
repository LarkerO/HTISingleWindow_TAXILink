<?php
declare(strict_types=1);
putenv('DB_DSN=sqlite::memory:');
putenv('DEMO_MODE=1');
putenv('APP_ENV=test');
require dirname(__DIR__) . '/vendor/autoload.php';
use app\domain\{Auth, AppError, Booking, Db, Demo, Dispatch, ObjectStorage, Operations, SystemLog};
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "✓ $label\n";
}
function rejects(callable $fn, string $key): void
{
    try {
        $fn();
    } catch (AppError $e) {
        check($e->key === $key, '拒绝：' . $key);
        return;
    }
    throw new RuntimeException('预期拒绝：' . $key);
}
Db::migrate();
Demo::seed();
$admin = Db::one('SELECT * FROM users WHERE platform_admin=1');
Auth::login(['sub' => 'ordinary']);
$ordinary = Db::one('SELECT * FROM users WHERE portal_sub=?', ['ordinary']);
$keyFile = sys_get_temp_dir() . '/taxilink-storage-' . Db::id() . '.key';
putenv('SETTINGS_KEY_FILE=' . $keyFile);
try {
    $base = [
        'bucket' => 'images-test',
        'region' => 'cn-hangzhou',
        'secretId' => 'AKIDTEST123456789',
        'secretKey' => 'SecretKey/test+123456789',
        'publicUrl' => 'https://cdn.test',
    ];
    foreach (
        ['oss' => 'cn-hangzhou', 'qiniu' => 'z0', 's3' => 'us-east-1', 'cos' => 'ap-guangzhou']
        as $provider => $region
    ) {
        $input = [
            ...$base,
            'provider' => $provider,
            'region' => $region,
            'bucket' => $provider === 'cos' ? 'images-1234567890' : 'images-test',
            'activate' => true,
        ];
        rejects(fn() => ObjectStorage::save($ordinary, $input), 'FORBIDDEN');
        $saved = ObjectStorage::save($admin, $input);
        check(
            $saved['configured'] && $saved['activeProvider'] === $provider,
            '服务独立保存并启用 ' . $provider,
        );
        check(
            !str_contains(Db::json($saved), $base['secretKey']),
            '配置响应不回显 ' . $provider . ' 密钥',
        );
        ObjectStorage::save($admin, [...$input, 'secretKey' => '']);
        check(
            ObjectStorage::config($provider)['secretKey'] === $base['secretKey'],
            '留空保留对应服务密钥 ' . $provider,
        );
        $plan = ObjectStorage::plan(
            $provider,
            ObjectStorage::config($provider),
            'taxilink/test/图 像.png',
            'image/png',
            'test',
            1700000000,
        );
        check(
            str_starts_with($plan['url'], 'https://') &&
                str_contains($plan['publicUrl'], '%E5%9B%BE%20%E5%83%8F.png'),
            '上传端点与公开路径正确编码 ' . $provider,
        );
        if ($provider === 'qiniu') {
            $parts = explode(':', $plan['fields']['token']);
            $policy = json_decode(base64_decode(strtr($parts[2], '-_', '+/')), true);
            check(
                $policy['scope'] === 'images-test:taxilink/test/图 像.png' &&
                    $policy['deadline'] === 1700000600 &&
                    $policy['insertOnly'] === 1,
                '七牛令牌仅授权本次对象且禁止覆盖',
            );
        }
        if ($provider === 's3') {
            check(
                str_contains(
                    implode('\n', $plan['headers']),
                    'SignedHeaders=content-md5;content-type;host;x-amz-content-sha256;x-amz-date',
                ),
                'S3 签入 Content-MD5 与负载哈希',
            );
        }
    }
    check(count(ObjectStorage::choices()['items']) === 4, '上传页可以选择四个已配置服务');
    rejects(
        fn() => ObjectStorage::save($admin, [
            ...$base,
            'provider' => 'qiniu',
            'region' => 'z0',
            'publicUrl' => '',
        ]),
        'VALIDATION',
    );
    $canonical =
        "PUT\n/test%24file.text\n\ndate:Fri, 24 May 2013 00:00:00 GMT\nhost:examplebucket.s3.amazonaws.com\nx-amz-content-sha256:44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072\nx-amz-date:20130524T000000Z\nx-amz-storage-class:REDUCED_REDUNDANCY\n\ndate;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class\n44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072";
    check(
        ObjectStorage::v4Signature(
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            '20130524T000000Z',
            'us-east-1',
            's3',
            $canonical,
        ) === '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
        'AWS 官方签名向量通过',
    );
    // OSS official PHP SDK SignerV4Test::testAuthHeader case 2.
    $stamp = gmdate('Ymd\THis\Z', 1702743657);
    $oss = "POST\n/bucket/object\nappend&position=0\ncontent-type:text/plain\nx-oss-content-sha256:UNSIGNED-PAYLOAD\nx-oss-date:$stamp\n\n\nUNSIGNED-PAYLOAD";
    check(
        ObjectStorage::v4Signature('sk', $stamp, 'cn-hangzhou', 'oss', $oss) ===
            'f7a645f23a0434ea90d5e2bb2bca90d0fda9ef4bc54a9c5008b2134e7c9023f2',
        '阿里云 OSS 官方 SDK 签名向量通过',
    );
    SystemLog::record(
        'mod',
        'command_finished',
        123,
        'request-alpha',
        'taxi.quote',
        150,
        'timeout',
    );
    SystemLog::record('passenger', 'connected', 124, result: 'success');
    SystemLog::record('mod', 'command_finished', 125, 'routine-beta', 'taxi.status', 10, 'success');
    check(
        count(SystemLog::recent(['hideRoutine' => '1'])) === 2,
        '默认隐藏常规成功同步但保留连接与故障',
    );
    check(
        count(SystemLog::recent(['hideRoutine' => '1', 'q' => 'routine-beta'])) === 1,
        '搜索请求 ID 时仍可找到隐藏的常规记录',
    );
    check(
        count(SystemLog::recent(['channel' => 'mod', 'result' => 'timeout', 'q' => 'alpha'])) === 1,
        '日志通道、结果与关键词组合筛选',
    );
    check(count(SystemLog::recent(['q' => '123'])) === 1, '日志支持搜索连接 ID');
    check(SystemLog::recent(['q' => '%']) === [], '日志搜索通配符按字面值匹配');
    check(
        str_contains(SystemLog::recent(['q' => 'alpha'])[0]['explanation'], '未在时限内响应'),
        '日志包含必要中文说明',
    );
    $q = Booking::quote($admin, [
        'pickupId' => 'riverside',
        'dropoffId' => 'museum',
        'passengers' => 1,
    ]);
    $o = Booking::create(
        $admin,
        ['quoteId' => $q['id'], 'companyIds' => ['jade']],
        'ops-test-' . Db::id(),
    );
    Dispatch::tick();
    $row = Db::one('SELECT * FROM orders WHERE id=?', [$o['id']]);
    rejects(fn() => Operations::physical($ordinary, $row, 'destroy', []), 'FORBIDDEN');
    Dispatch::ingest(
        $o['id'],
        'reconcile.status',
        [
            'phase' => 'ACTIVE',
            'assistanceRequired' => true,
            'obstruction' => ['blockers' => [['orderId' => 'other']]],
        ],
        time() * 1000 + 10,
    );
    $row = Db::one('SELECT * FROM orders WHERE id=?', [$o['id']]);
    check($row['state'] === 'NEEDS_ATTENTION', '占轨超时升级为等待调度协助');
    check(
        !isset(Booking::passengerView($row)['telemetry']['obstruction']['blockers']),
        '乘客接口不泄漏其他订单号',
    );
    Dispatch::ingest(
        $o['id'],
        'reconcile.status',
        ['phase' => 'ACTIVE', 'assistanceRequired' => false],
        time() * 1000 + 20,
    );
    check(
        Db::one('SELECT state FROM orders WHERE id=?', [$o['id']])['state'] === 'ACTIVE',
        '占用解除自动恢复，保留人工取消等其他协助原因',
    );
    Db::run("UPDATE orders SET state='NEEDS_ATTENTION' WHERE id=?", [$o['id']]);
    Dispatch::ingest(
        $o['id'],
        'reconcile.status',
        ['phase' => 'ACTIVE', 'assistanceRequired' => false],
        time() * 1000 + 30,
    );
    check(
        Db::one('SELECT state FROM orders WHERE id=?', [$o['id']])['state'] === 'NEEDS_ATTENTION',
        '不会清除其他原因的人工关注状态',
    );
} finally {
    if (is_file($keyFile)) {
        unlink($keyFile);
    }
    putenv('SETTINGS_KEY_FILE');
}
