<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use app\domain\{AppError, Booking, Db, Demo, Install, SystemLog};
Webman\Config::load(dirname(__DIR__) . '/config', ['route', 'process']);

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "✓ $label\n";
}
function install(string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(Install::class, $method))->invoke(null, ...$args);
}
$pdo = Db::pdo();
$installationConnection = install('database', [
    'DB_DSN' => getenv('DB_DSN'),
    'DB_USER' => getenv('DB_USER') ?: '',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: '',
]);
check(
    (int) $installationConnection->query('SELECT 1')->fetchColumn() === 1,
    '安装向导的 PDO 选项可实际连接数据库',
);
$installationConnection = null;
install('requireEmpty', $pdo);
check(true, '安装接受空数据库');
$config = install(
    'storageConfig',
    [
        'dbDriver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        'dbHost' => '127.0.0.1',
        'dbPort' => Db::pgsql() ? '5432' : '3306',
        'dbName' => 'taxilink',
        'dbUser' => 'taxilink',
        'dbPassword' => 'test',
        'redisUrl' => 'tcp://127.0.0.1:6379',
    ],
    false,
);
check(
    str_starts_with($config['DB_DSN'], Db::pgsql() ? 'pgsql:' : 'mysql:'),
    '安装生成所选数据库连接配置',
);
install('lockDatabase', $pdo);
$other = Db::connect(getenv('DB_DSN'), getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '');
try {
    install('lockDatabase', $other);
    throw new LogicException('并发安装未被锁阻止');
} catch (RuntimeException $e) {
    check(!($e instanceof LogicException), '第二个安装连接无法取得同库安装锁');
}
Db::migrate();
try {
    install('requireEmpty', $pdo);
    throw new LogicException('非空库未被拒绝');
} catch (AppError $e) {
    check($e->key === 'INSTALL_DB_NOT_EMPTY', '安装拒绝非空数据库');
}
// Simulate upgrading an older schema, then rerun migration twice.
$pdo->exec('ALTER TABLE companies DROP COLUMN image_url');
$pdo->exec('ALTER TABLE vehicles DROP COLUMN image_url');
$pdo->exec('ALTER TABLE oauth_states DROP COLUMN return_to');
$pdo->exec('ALTER TABLE orders DROP COLUMN queue_ticket');
$pdo->exec('ALTER TABLE orders DROP COLUMN revision');
$pdo->exec('ALTER TABLE orders DROP COLUMN vehicle_pending');
Db::migrate();
Db::migrate();
check(
    Db::one('SELECT ticket FROM dispatch_sequence WHERE id=1') !== null,
    '旧结构升级与重复迁移成功',
);
Db::all('SELECT image_url FROM companies');
Db::all('SELECT image_url FROM vehicles');
Db::all('SELECT return_to FROM oauth_states');
Db::all('SELECT queue_ticket,revision,vehicle_pending FROM orders');
$text = str_repeat('轨迹🚕', 20000);
Db::insert('quotes', [
    'id' => 'large',
    'user_id' => 'user',
    'pickup_id' => 'a',
    'dropoff_id' => 'b',
    'passengers' => 1,
    'offers' => $text,
    'expires' => time(),
]);
check(
    Db::one('SELECT offers FROM quotes WHERE id=?', ['large'])['offers'] === $text,
    '大于 64 KiB 的中文与 emoji 数据完整保存',
);
try {
    Db::tx(function () {
        Db::insert('point_groups', ['id' => 'rollback', 'name' => '回滚']);
        throw new RuntimeException('rollback');
    });
} catch (RuntimeException) {
}
check(
    Db::one('SELECT id FROM point_groups WHERE id=?', ['rollback']) === null,
    '业务异常回滚整个事务',
);

// Run multiple PHP processes against the same identity, as separate HTTP workers would.
$code =
    'require ' .
    var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
    '; ' .
    'app\\domain\\Auth::login(["sub"=>"parallel-user","preferred_username"=>"并发乘客"]);';
$children = [];
for ($i = 0; $i < 6; $i++) {
    $process = proc_open([PHP_BINARY, '-r', $code], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('无法创建并发测试进程');
    }
    $children[] = $process;
}
foreach ($children as $process) {
    check(proc_close($process) === 0, '并发登录进程成功');
}
check(
    (int) Db::one('SELECT COUNT(*) AS n FROM users WHERE portal_sub=?', ['parallel-user'])['n'] ===
        1,
    '并发首次登录只创建一个账号',
);
check((int) Db::one('SELECT COUNT(*) AS n FROM sessions')['n'] === 6, '并发登录保留各自会话');
Demo::seed();
$user = Db::one('SELECT * FROM users WHERE id=?', ['demo-user']);
$quote = Booking::quote($user, [
    'pickupId' => 'riverside',
    'dropoffId' => 'museum',
    'passengers' => 1,
]);
$code =
    'require ' .
    var_export(dirname(__DIR__) . '/vendor/autoload.php', true) .
    '; ' .
    '$u=app\\domain\\Db::one("SELECT * FROM users WHERE id=?", ["demo-user"]); ' .
    'app\\domain\\Booking::create($u, ["quoteId"=>' .
    var_export($quote['id'], true) .
    ',"companyIds"=>["jade"]], "parallel-order");';
$children = [];
for ($i = 0; $i < 6; $i++) {
    $process = proc_open([PHP_BINARY, '-r', $code], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('无法创建并发订单进程');
    }
    $children[] = $process;
}
foreach ($children as $process) {
    check(proc_close($process) === 0, '并发幂等下单进程成功');
}
check(
    (int) Db::one('SELECT COUNT(*) AS n FROM orders WHERE idempotency_key=?', ['parallel-order'])[
        'n'
    ] === 1,
    '并发重复下单只创建一个订单',
);
if (Db::pgsql()) {
    $pdo->exec('ALTER TABLE system_logs RENAME TO system_logs_backup');
    Db::tx(function () {
        SystemLog::record('gateway', 'test');
        Db::insert('point_groups', ['id' => 'log-failure', 'name' => '日志失败后仍提交']);
    });
    check(
        Db::one('SELECT id FROM point_groups WHERE id=?', ['log-failure']) !== null,
        '日志失败不污染 PostgreSQL 业务事务',
    );
    $pdo->exec('ALTER TABLE system_logs_backup RENAME TO system_logs');
}
// Verify row locking across two connections, without an indefinite blocking test.
$pdo->beginTransaction();
$pdo->query('SELECT * FROM dispatch_sequence WHERE id=1 FOR UPDATE');
$other->beginTransaction();
try {
    $other->query('SELECT * FROM dispatch_sequence WHERE id=1 FOR UPDATE NOWAIT');
    throw new LogicException('行锁没有生效');
} catch (PDOException) {
    check(true, '订单排队序列的行锁阻止另一连接抢占');
} finally {
    $other->rollBack();
    $pdo->rollBack();
}
