<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'test') {
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';
use app\domain\{AppError, Booking, Db, Demo, QuoteRoutes};
Db::migrate();
Demo::seed();
while (($line = fgets(STDIN)) !== false) {
    try {
        $input = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (($input['action'] ?? '') === 'purge') {
            Db::run('DELETE FROM route_cache');
            $result = ['ok' => true];
        } elseif (($input['action'] ?? '') === 'booking') {
            $result = Booking::quote(
                ['id' => 'demo-user'],
                ['pickupId' => 'riverside', 'dropoffId' => 'museum', 'passengers' => 1],
            );
        } else {
            $requests = [];
            foreach ($input['params'] as $params) {
                $requests[QuoteRoutes::key($params)] = $params;
            }
            $started = microtime(true);
            $result = [
                'routes' => array_map(
                    static fn($v) => $v instanceof AppError ? ['error' => $v->key] : $v,
                    QuoteRoutes::fetch($requests, $input['budget'] ?? 2),
                ),
                'elapsed' => microtime(true) - $started,
            ];
        }
        echo Db::json($result) . "\n";
    } catch (Throwable $e) {
        echo Db::json(['driverError' => $e->getMessage()]) . "\n";
    }
}
