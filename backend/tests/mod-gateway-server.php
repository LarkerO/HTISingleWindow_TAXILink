<?php
declare(strict_types=1);

// Started only by the mod's optional PlatformGatewayTest with an isolated SQLite database.
if (getenv('APP_ENV') !== 'test' || getenv('TAXILINK_GATEWAY_TEST') !== '1') {
    throw new RuntimeException('This entry point is restricted to integration tests');
}
require dirname(__DIR__) . '/vendor/autoload.php';
\app\domain\Env::boot();
\app\domain\Db::migrate();
\Workerman\Worker::$logFile = getenv('TAXILINK_TEST_LOG');
$worker = new \Workerman\Worker(getenv('MOD_LISTEN'));
$worker->count = 1;
$worker->onWorkerStart = static function ($worker): void {
    worker_bind($worker, new \app\process\ModGateway());
};
\Workerman\Worker::runAll();
