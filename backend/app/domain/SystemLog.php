<?php
declare(strict_types=1);

namespace app\domain;

final class SystemLog
{
    public static function record(
        string $channel,
        string $event,
        ?int $connection = null,
        ?string $request = null,
        ?string $command = null,
        ?int $duration = null,
        ?string $result = null,
    ): void {
        $savepoint = false;
        try {
            // PostgreSQL errors abort the surrounding transaction unless rolled back to a savepoint.
            if (Db::pgsql() && Db::pdo()->inTransaction()) {
                Db::pdo()->exec('SAVEPOINT taxilink_system_log');
                $savepoint = true;
            }
            Db::insert('system_logs', [
                'id' => Db::id(),
                'created_at' => (int) (microtime(true) * 1000),
                'channel' => $channel,
                'event' => $event,
                'connection_id' => $connection,
                'request_id' => $request,
                'command' => $command,
                'duration_ms' => $duration,
                'result' => $result,
            ]);
            $cutoff = Db::one(
                'SELECT created_at,id FROM system_logs ORDER BY created_at DESC,id DESC LIMIT 1 OFFSET 999',
            );
            if ($cutoff) {
                Db::run('DELETE FROM system_logs WHERE created_at<? OR (created_at=? AND id<?)', [
                    $cutoff['created_at'],
                    $cutoff['created_at'],
                    $cutoff['id'],
                ]);
            }
            if ($savepoint) {
                Db::pdo()->exec('RELEASE SAVEPOINT taxilink_system_log');
            }
        } catch (\Throwable) {
            if ($savepoint && Db::pdo()->inTransaction()) {
                Db::pdo()->exec('ROLLBACK TO SAVEPOINT taxilink_system_log');
                Db::pdo()->exec('RELEASE SAVEPOINT taxilink_system_log');
            }
            // Logging must not interrupt the ride or WebSocket connection.
            static $lastWarning = 0;
            if (time() - $lastWarning >= 60) {
                $lastWarning = time();
                \support\Log::warning('无法记录 WebSocket 系统日志，请检查数据库迁移与连接');
            }
        }
    }

    public static function recent(): array
    {
        try {
            return Db::all('SELECT * FROM system_logs ORDER BY created_at DESC,id DESC LIMIT 1000');
        } catch (\PDOException $e) {
            if (
                in_array($e->getCode(), ['42S02', '42P01'], true) ||
                (Db::sqlite() && str_contains($e->getMessage(), 'no such table: system_logs'))
            ) {
                throw new AppError(
                    'SYSTEM_LOG_MIGRATION_REQUIRED',
                    '系统日志表尚未创建。请在当前部署目录运行 php backend/bin/console.php migrate，再重启后端。',
                    503,
                );
            }
            throw $e;
        }
    }
}
