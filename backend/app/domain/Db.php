<?php
declare(strict_types=1);
namespace app\domain;
use PDO;

final class Db
{
    private static ?PDO $pdo = null;
    public static function pdo(): PDO
    {
        Env::boot();
        if (self::$pdo) {
            return self::$pdo;
        }
        $dsn = Env::get('DB_DSN', 'sqlite:runtime/taxilink.sqlite');
        if (
            str_starts_with($dsn, 'sqlite:') &&
            !str_starts_with(substr($dsn, 7), '/') &&
            !preg_match('/^[A-Z]:/i', substr($dsn, 7)) &&
            $dsn !== 'sqlite::memory:'
        ) {
            $dsn = 'sqlite:' . dirname(__DIR__, 2) . '/' . substr($dsn, 7);
        }
        self::$pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (self::sqlite()) {
            self::$pdo->exec('PRAGMA foreign_keys=ON');
            self::$pdo->exec('PRAGMA busy_timeout=5000');
            self::$pdo->exec('PRAGMA journal_mode=WAL');
        }
        return self::$pdo;
    }
    public static function sqlite(): bool
    {
        return self::$pdo?->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
    public static function run(string $sql, array $args = []): \PDOStatement
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($args);
        return $s;
    }
    public static function all(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }
    public static function one(string $sql, array $args = []): ?array
    {
        return self::run($sql, $args)->fetch() ?: null;
    }
    public static function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        self::run(
            'INSERT INTO ' .
                $table .
                ' (' .
                implode(',', $columns) .
                ') VALUES (' .
                implode(',', array_fill(0, count($columns), '?')) .
                ')',
            array_values($row),
        );
    }
    public static function tx(callable $fn): mixed
    {
        $p = self::pdo();
        $p->beginTransaction();
        try {
            $result = $fn();
            $p->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($p->inTransaction()) {
                $p->rollBack();
            }
            throw $e;
        }
    }
    public static function lock(): string
    {
        return self::sqlite() ? '' : ' FOR UPDATE';
    }
    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }
    public static function json(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
    public static function data(?string $value): array
    {
        return $value ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : [];
    }
    public static function migrate(): void
    {
        $definitions = [
            'users' =>
                'id VARCHAR(64) PRIMARY KEY, portal_sub VARCHAR(128) UNIQUE NOT NULL, username VARCHAR(120) NOT NULL, avatar TEXT, first_login BIGINT NOT NULL, platform_admin INTEGER NOT NULL DEFAULT 0',
            'sessions' =>
                'id VARCHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL, csrf VARCHAR(64) NOT NULL, expires BIGINT NOT NULL',
            'oauth_states' =>
                'id VARCHAR(64) PRIMARY KEY, verifier VARCHAR(128) NOT NULL, destination VARCHAR(20) NOT NULL, expires BIGINT NOT NULL',
            'companies' =>
                'id VARCHAR(64) PRIMARY KEY, name VARCHAR(120) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, fare TEXT NOT NULL',
            'memberships' =>
                'id VARCHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL, company_id VARCHAR(64) NOT NULL, role VARCHAR(32) NOT NULL, UNIQUE(user_id,company_id,role)',
            'invitations' =>
                'id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, role VARCHAR(32) NOT NULL, token_hash VARCHAR(64) UNIQUE NOT NULL, expires BIGINT NOT NULL, used_by VARCHAR(64)',
            'centers' =>
                'id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, depots TEXT NOT NULL',
            'vehicles' =>
                'id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, capacity INTEGER NOT NULL, config TEXT NOT NULL',
            'point_groups' => 'id VARCHAR(64) PRIMARY KEY, name VARCHAR(120) NOT NULL',
            'points' =>
                'id VARCHAR(64) PRIMARY KEY, group_id VARCHAR(64), name VARCHAR(120) NOT NULL, description TEXT, enabled INTEGER NOT NULL DEFAULT 1',
            'point_platforms' =>
                'id VARCHAR(64) PRIMARY KEY, point_id VARCHAR(64) NOT NULL, platform_id VARCHAR(32) NOT NULL, UNIQUE(point_id,platform_id)',
            'company_points' =>
                'id VARCHAR(64) PRIMARY KEY, company_id VARCHAR(64) NOT NULL, point_id VARCHAR(64) NOT NULL, UNIQUE(company_id,point_id)',
            'quotes' =>
                'id VARCHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL, pickup_id VARCHAR(64) NOT NULL, dropoff_id VARCHAR(64) NOT NULL, passengers INTEGER NOT NULL, offers TEXT NOT NULL, expires BIGINT NOT NULL',
            'orders' =>
                'id VARCHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, pickup_id VARCHAR(64) NOT NULL, dropoff_id VARCHAR(64) NOT NULL, passengers INTEGER NOT NULL, state VARCHAR(32) NOT NULL, company_id VARCHAR(64), candidate_id VARCHAR(64), snapshot TEXT NOT NULL, telemetry TEXT NOT NULL, amount BIGINT NOT NULL DEFAULT 0, paid BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, billing_started BIGINT, billing_ended BIGINT, queue_until BIGINT NOT NULL, UNIQUE(user_id,idempotency_key)',
            'candidates' =>
                'id VARCHAR(64) PRIMARY KEY, order_id VARCHAR(64) NOT NULL, company_id VARCHAR(64) NOT NULL, center_id VARCHAR(64) NOT NULL, pickup_distance DOUBLE NOT NULL, state VARCHAR(32) NOT NULL, params TEXT NOT NULL, offer TEXT NOT NULL',
            'events' =>
                'id VARCHAR(64) PRIMARY KEY, order_id VARCHAR(64) NOT NULL, event_key VARCHAR(128) UNIQUE NOT NULL, event_type VARCHAR(80) NOT NULL, data TEXT NOT NULL, created_at BIGINT NOT NULL',
            'payments' =>
                'id VARCHAR(64) PRIMARY KEY, order_id VARCHAR(64) NOT NULL, kind VARCHAR(32) NOT NULL, amount BIGINT NOT NULL, created_at BIGINT NOT NULL, demonstration INTEGER NOT NULL DEFAULT 1',
            'audit_logs' =>
                'id VARCHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL, action VARCHAR(80) NOT NULL, target VARCHAR(64) NOT NULL, data TEXT NOT NULL, created_at BIGINT NOT NULL',
            'mod_inbox' =>
                'id VARCHAR(64) PRIMARY KEY, data TEXT NOT NULL, processed INTEGER NOT NULL DEFAULT 0, created_at BIGINT NOT NULL',
            'demo_rides' =>
                'id VARCHAR(64) PRIMARY KEY, params TEXT NOT NULL, created_at BIGINT NOT NULL, departed_at BIGINT, destroyed INTEGER NOT NULL DEFAULT 0',
            'dispatch_sequence' => 'id INTEGER PRIMARY KEY, ticket BIGINT NOT NULL',
        ];
        foreach ($definitions as $table => $definition) {
            self::pdo()->exec("CREATE TABLE IF NOT EXISTS $table ($definition)");
        }
        $columns = self::sqlite()
            ? array_column(self::all('PRAGMA table_info(orders)'), 'name')
            : array_column(
                self::all(
                    "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders'",
                ),
                'COLUMN_NAME',
            );
        if (!in_array('queue_ticket', $columns, true)) {
            self::pdo()->exec(
                'ALTER TABLE orders ADD COLUMN queue_ticket BIGINT NOT NULL DEFAULT 0',
            );
        }
        if (!self::one('SELECT id FROM dispatch_sequence WHERE id=1')) {
            self::insert('dispatch_sequence', ['id' => 1, 'ticket' => 0]);
        }
        foreach (
            [
                'orders' => 'state,created_at',
                'candidates' => 'order_id,state',
                'events' => 'order_id,created_at',
                'memberships' => 'user_id',
                'centers' => 'company_id',
                'point_platforms' => 'point_id',
            ]
            as $table => $columns
        ) {
            $name = 'idx_' . $table;
            if (self::sqlite()) {
                self::pdo()->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($columns)");
            } elseif (
                !self::one(
                    'SELECT INDEX_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
                    [$table, $name],
                )
            ) {
                self::pdo()->exec("CREATE INDEX $name ON $table ($columns)");
            }
        }
    }
}
