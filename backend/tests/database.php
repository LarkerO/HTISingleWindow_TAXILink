<?php
declare(strict_types=1);

// Creates only random taxilink_test_* databases. Never runs fixtures against the supplied database.
require dirname(__DIR__) . '/vendor/autoload.php';
use app\domain\Db;

$dsn = getenv('TAXILINK_TEST_ADMIN_DSN') ?: '';
$driver = strstr($dsn, ':', true);
if (!in_array($driver, ['mysql', 'pgsql'], true)) {
    throw new RuntimeException(
        '请设置 TAXILINK_TEST_ADMIN_DSN（mysql:... 或 pgsql:...）及 DB_USER、DB_PASSWORD；账号需有创建临时数据库权限',
    );
}
$admin = Db::connect($dsn, getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '');
$baseDsn = preg_replace('/;?dbname=[^;]*/', '', $dsn);
$baseDsn = rtrim($baseDsn, ';');
foreach (['database-contract.php', 'domain.php', 'cashier.php', 'oauth'] as $suite) {
    $name = 'taxilink_test_' . bin2hex(random_bytes(8));
    $quoted = $driver === 'pgsql' ? '"' . $name . '"' : '`' . $name . '`';
    $admin->exec(
        'CREATE DATABASE ' .
            $quoted .
            ($driver === 'pgsql'
                ? " ENCODING 'UTF8' TEMPLATE template0"
                : ' CHARACTER SET utf8mb4'),
    );
    try {
        $env = getenv();
        $env['TAXILINK_TEST_DB_DSN'] = $baseDsn . ';dbname=' . $name;
        $env['DB_DSN'] = $env['TAXILINK_TEST_DB_DSN'];
        $env['REDIS_URL'] = '';
        $env['APP_ENV'] = 'test';
        $env['DEMO_MODE'] = '1';
        $process = proc_open(
            $suite === 'oauth'
                ? ['node', '--test', dirname(__DIR__, 2) . '/tests/oauth/portal.test.mjs']
                : [PHP_BINARY, __DIR__ . '/' . $suite],
            [STDIN, STDOUT, STDERR],
            $pipes,
            $suite === 'oauth' ? dirname(__DIR__, 2) : dirname(__DIR__),
            $env,
        );
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException("$driver / $suite 验证失败");
        }
        echo "✓ $driver / $suite\n";
    } finally {
        // $name is generated above, never read from user input.
        $admin->exec('DROP DATABASE ' . $quoted);
    }
}
