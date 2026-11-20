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

    public static function recent(array $filters = []): array
    {
        $where = [];
        $args = [];
        foreach (['channel', 'event', 'result', 'command'] as $field) {
            $value = $filters[$field] ?? '';
            if (!is_string($value) || strlen($value) > 128) {
                throw new AppError('VALIDATION', '日志筛选格式无效', 422);
            }
            if ($value !== '') {
                $where[] = "$field=?";
                $args[] = $value;
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            $value = $filters[$field] ?? '';
            if ($value === '') {
                continue;
            }
            if (
                !is_scalar($value) ||
                !ctype_digit((string) $value) ||
                strlen((string) $value) > 15
            ) {
                throw new AppError('VALIDATION', '日志时间应为毫秒时间戳', 422);
            }
            $where[] = "created_at $operator ?";
            $args[] = (int) $value;
        }
        $query = $filters['q'] ?? '';
        if (($filters['hideRoutine'] ?? '') === '1' && $query === '') {
            $where[] =
                "NOT (COALESCE(command,'') IN ('taxi.status','taxi.geometry','taxi.logs','taxi.list','player.position') AND (event='command_sent' OR (event='command_finished' AND result='success')))";
        }
        if (!is_string($query) || mb_strlen($query) > 128) {
            throw new AppError('VALIDATION', '日志搜索最多 128 字符', 422);
        }
        if ($query !== '') {
            $terms = [];
            $pattern =
                '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)) . '%';
            foreach (
                [
                    'request_id',
                    'command',
                    'event',
                    'result',
                    'channel',
                    'CAST(connection_id AS ' .
                    (Db::sqlite() || Db::pgsql() ? 'TEXT' : 'CHAR') .
                    ')',
                ]
                as $field
            ) {
                $terms[] = "LOWER($field) LIKE ? ESCAPE '!'";
                $args[] = $pattern;
            }
            $where[] = '(' . implode(' OR ', $terms) . ')';
        }
        try {
            $rows = Db::all(
                'SELECT * FROM system_logs' .
                    ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
                    ' ORDER BY created_at DESC,id DESC LIMIT 1000',
                $args,
            );
            foreach ($rows as &$row) {
                $row['explanation'] = self::explanation($row);
            }
            return $rows;
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
    public static function explanation(array $row): string
    {
        $commands = [
            'taxi.quote' => '线路预览与报价',
            'player.position' => '读取当前玩家在线位置',
            'taxi.status' => '核对车辆状态',
            'taxi.geometry' => '读取行程轨迹',
            'taxi.logs' => '回放订单动态',
            'taxi.create' => '申请派车',
            'taxi.destroy' => '清除空车并恢复车厂',
            'taxi.doors' => '管理员开关车门',
            'taxi.clear_returning' => '清除占轨的空载回库车辆',
        ];
        $results = [
            'timeout' => '模组未在时限内响应；先查游戏日志和连接，不要重复创建新订单。',
            'mod_error' => '模组拒绝指令；请结合订单动态检查有人乘坐、车辆移动或线路条件。',
            'handshake_invalid' => '握手签名未通过；核对双方 SM2 密钥、serverId 和时间。',
            'origin_or_path' =>
                '乘客端来源或 WebSocket 路径不匹配；核对乘客端接入登记及 /ws 代理。',
            'session_required' => '乘客会话无效或已过期，请重新登录。',
            'success' => '指令已成功返回；成功报价不代表已派车。',
        ];
        return ($commands[$row['command'] ?? ''] ?? '') .
            ($row['command'] ?? '' ? '。' : '') .
            ($results[$row['result'] ?? ''] ??
                '连接事件或常规状态同步。相同请求 ID 可关联指令下发与完成记录。');
    }
}
