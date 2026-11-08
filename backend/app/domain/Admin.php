<?php
declare(strict_types=1);
namespace app\domain;

final class Admin
{
    public static function list(array $u, string $resource, ?string $company = null): array
    {
        $tables = [
            'companies',
            'centers',
            'vehicles',
            'point_groups',
            'points',
            'memberships',
            'orders',
            'audit_logs',
            'payments',
        ];
        if (!in_array($resource, $tables, true)) {
            throw new AppError('NOT_FOUND', '资源不存在', 404);
        }
        if (in_array($resource, ['point_groups', 'points'], true)) {
            if (
                !$u['platform_admin'] &&
                !Db::one('SELECT id FROM memberships WHERE user_id=?', [$u['id']])
            ) {
                throw new AppError('FORBIDDEN', '需要管理权限', 403);
            }
            return Db::all("SELECT * FROM $resource ORDER BY name");
        }
        if ($resource === 'companies') {
            if ($u['platform_admin']) {
                return Db::all('SELECT * FROM companies ORDER BY name');
            }
            return Db::all(
                'SELECT DISTINCT c.* FROM companies c JOIN memberships m ON m.company_id=c.id WHERE m.user_id=?',
                [$u['id']],
            );
        }
        if ($resource === 'audit_logs' || $resource === 'payments') {
            Auth::platform($u);
            return Db::all("SELECT * FROM $resource ORDER BY created_at DESC LIMIT 200");
        }
        if ($company) {
            Auth::company($u, $company);
            $rows =
                $resource === 'orders'
                    ? Db::all(
                        'SELECT o.* FROM orders o WHERE o.company_id=? OR (o.company_id IS NULL AND EXISTS (SELECT 1 FROM candidates c WHERE c.order_id=o.id AND c.company_id=?)) ORDER BY o.created_at DESC LIMIT 200',
                        [$company, $company],
                    )
                    : Db::all("SELECT * FROM $resource WHERE company_id=?", [$company]);
        } else {
            Auth::platform($u);
            $rows = Db::all(
                "SELECT * FROM $resource" .
                    ($resource === 'orders' ? ' ORDER BY created_at DESC LIMIT 200' : ''),
            );
        }
        if ($resource === 'orders') {
            return array_map(Booking::view(...), $rows);
        }
        if ($resource === 'memberships') {
            foreach ($rows as &$row) {
                $row['username'] =
                    Db::one('SELECT username FROM users WHERE id=?', [$row['user_id']])[
                        'username'
                    ] ?? '';
            }
        }
        return $rows;
    }
    private static function name(array $in): string
    {
        $s = trim((string) ($in['name'] ?? ''));
        if ($s === '' || mb_strlen($s) > 120) {
            throw new AppError('INVALID_NAME', '名称长度应为 1–120 个字符');
        }
        return $s;
    }
    public static function save(array $u, string $resource, array $in, ?string $id = null): array
    {
        if (
            !in_array(
                $resource,
                ['companies', 'centers', 'vehicles', 'point_groups', 'points'],
                true,
            )
        ) {
            throw new AppError('NOT_FOUND', '资源不存在', 404);
        }
        $old = $id ? Db::one("SELECT * FROM $resource WHERE id=?", [$id]) : null;
        if ($id && !$old) {
            throw new AppError('NOT_FOUND', '记录不存在', 404);
        }
        $id ??= Db::id();
        $name = self::name($in);
        $row = ['name' => $name];
        if (in_array($resource, ['companies', 'points', 'point_groups'], true)) {
            Auth::platform($u);
        } else {
            $company = $old['company_id'] ?? (string) ($in['companyId'] ?? '');
            Auth::company($u, $company, true);
            if (!Db::one('SELECT id FROM companies WHERE id=?', [$company])) {
                throw new AppError('COMPANY_REQUIRED', '企业不存在');
            }
            $row['company_id'] = $company;
        }
        if (in_array($resource, ['companies', 'centers', 'points'], true)) {
            $row['enabled'] = (int) (bool) ($in['enabled'] ?? true);
        }
        if ($resource === 'companies') {
            $row['fare'] = Db::json(Fare::validate($in['fare'] ?? Db::data($old['fare'] ?? null)));
        }
        if ($resource === 'points') {
            $group = $in['groupId'] ?? null;
            if ($group && !Db::one('SELECT id FROM point_groups WHERE id=?', [$group])) {
                throw new AppError('GROUP_INVALID', '点组不存在');
            }
            $row['group_id'] = $group ?: null;
            $row['description'] = mb_substr((string) ($in['description'] ?? ''), 0, 500);
            $platforms = $in['platformIds'] ?? [];
            if (!is_array($platforms) || !$platforms) {
                throw new AppError('PLATFORMS_REQUIRED', '请关联至少一条站台轨道');
            }
            $catalog = array_column(Bridge::catalog('platforms'), 'id');
            foreach ($platforms as $p) {
                if (!is_string($p) || !in_array($p, $catalog, true)) {
                    throw new AppError('INVALID_PLATFORM', '站台轨道不存在');
                }
            }
        }
        if ($resource === 'centers') {
            $depots = $in['depots'] ?? [];
            if (!is_array($depots) || !$depots) {
                throw new AppError('DEPOTS_REQUIRED', '请配置至少一个单轨专用车厂');
            }
            $catalog = Bridge::catalog('depots');
            $sidings = Bridge::catalog('sidings');
            foreach ($depots as $d) {
                $depot =
                    array_values(
                        array_filter($catalog, fn($v) => $v['id'] === ($d['depotId'] ?? null)),
                    )[0] ?? null;
                $siding =
                    array_values(
                        array_filter($sidings, fn($v) => $v['id'] === ($d['sidingId'] ?? null)),
                    )[0] ?? null;
                if (
                    !$depot ||
                    !$siding ||
                    $siding['depotId'] !== $depot['id'] ||
                    $depot['sidingCount'] !== 1 ||
                    !$depot['allowedForTaxi']
                ) {
                    throw new AppError(
                        'INVALID_DEPOT',
                        '需选择已授权且仅有一条所属停车轨道的专用车厂',
                    );
                }
                foreach (Db::all('SELECT id,depots FROM centers WHERE id<>?', [$id]) as $other) {
                    if (
                        in_array(
                            $d['depotId'],
                            array_column(Db::data($other['depots']), 'depotId'),
                            true,
                        )
                    ) {
                        throw new AppError('DEPOT_ASSIGNED', '车厂已被其他车辆中心使用', 409);
                    }
                }
            }
            $row['depots'] = Db::json($depots);
        }
        if ($resource === 'vehicles') {
            $config = $in['config'] ?? [];
            $cars = $config['vehicleCars'] ?? [];
            if (!$cars || count($cars) > 16) {
                throw new AppError('INVALID_VEHICLE', '请配置 1–16 节真实车型');
            }
            $capacity = 0;
            $cleanCars = [];
            foreach ($cars as $car) {
                if (
                    !is_string($car['vehicleId'] ?? null) ||
                    trim($car['vehicleId']) === '' ||
                    mb_strlen($car['vehicleId']) > 256 ||
                    !is_int($car['capacity'] ?? null) ||
                    $car['capacity'] < 1 ||
                    $car['capacity'] > 1000
                ) {
                    throw new AppError('INVALID_VEHICLE', '车型资源及容量无效');
                }
                $length = $car['length'] ?? 0;
                if (!is_numeric($length)) {
                    throw new AppError('INVALID_VEHICLE', '车身长度无效');
                }
                $cleanCar = ['vehicleId' => $car['vehicleId'], 'capacity' => $car['capacity']];
                foreach (
                    [
                        'length' => [0.5, 100],
                        'width' => [0.1, 20],
                        'bogie1Position' => [-$length / 2, $length / 2],
                        'bogie2Position' => [-$length / 2, $length / 2],
                        'couplingPadding1' => [0, 10],
                        'couplingPadding2' => [0, 10],
                    ]
                    as $key => [$min, $max]
                ) {
                    if (!is_numeric($car[$key] ?? null) || $car[$key] < $min || $car[$key] > $max) {
                        throw new AppError('INVALID_VEHICLE', '车身参数无效：' . $key);
                    }
                    $cleanCar[$key] = (float) $car[$key];
                }
                $cleanCars[] = $cleanCar;
                $capacity += $car['capacity'];
            }
            $cleanConfig = ['vehicleCars' => $cleanCars];
            foreach (
                [
                    'maxSpeedKmh' => [1, 160],
                    'accelerationMps2' => [0.1, 4],
                    'decelerationMps2' => [0.1, 4],
                ]
                as $key => [$min, $max]
            ) {
                if (
                    !is_numeric($config[$key] ?? null) ||
                    $config[$key] < $min ||
                    $config[$key] > $max
                ) {
                    throw new AppError('INVALID_VEHICLE', '行驶参数无效：' . $key);
                }
                $cleanConfig[$key] = (float) $config[$key];
            }
            $row['capacity'] = $capacity;
            $row['config'] = Db::json($cleanConfig);
        }
        return Db::tx(function () use ($u, $resource, $in, $old, $id, $row) {
            if ($old) {
                Db::run(
                    "UPDATE $resource SET " .
                        implode(',', array_map(fn($k) => "$k=?", array_keys($row))) .
                        ' WHERE id=?',
                    [...array_values($row), $id],
                );
            } else {
                Db::insert($resource, ['id' => $id, ...$row]);
            }
            if ($resource === 'points') {
                Db::run('DELETE FROM point_platforms WHERE point_id=?', [$id]);
                foreach (array_unique($in['platformIds']) as $platform) {
                    Db::insert('point_platforms', [
                        'id' => Db::id(),
                        'point_id' => $id,
                        'platform_id' => $platform,
                    ]);
                }
            }
            self::audit($u, $resource . '.save', $id, $row);
            return ['id' => $id, ...$row];
        });
    }
    public static function scope(array $u, string $company, array $in): array
    {
        Auth::company($u, $company, true);
        $points = $in['pointIds'] ?? [];
        foreach ($in['groupIds'] ?? [] as $group) {
            array_push(
                $points,
                ...array_column(Db::all('SELECT id FROM points WHERE group_id=?', [$group]), 'id'),
            );
        }
        if (!in_array($in['action'] ?? '', ['add', 'remove'], true) || !is_array($points)) {
            throw new AppError('INVALID_SCOPE', '请选择批量添加或移除');
        }
        return Db::tx(function () use ($u, $company, $in, $points) {
            foreach (array_unique($points) as $point) {
                if (!Db::one('SELECT id FROM points WHERE id=?', [$point])) {
                    throw new AppError('INVALID_POINT', '虚拟点不存在');
                }
                if ($in['action'] === 'remove') {
                    Db::run('DELETE FROM company_points WHERE company_id=? AND point_id=?', [
                        $company,
                        $point,
                    ]);
                } elseif (
                    !Db::one('SELECT id FROM company_points WHERE company_id=? AND point_id=?', [
                        $company,
                        $point,
                    ])
                ) {
                    Db::insert('company_points', [
                        'id' => Db::id(),
                        'company_id' => $company,
                        'point_id' => $point,
                    ]);
                }
            }
            self::audit($u, 'scope.' . $in['action'], $company, $in);
            return Db::all('SELECT point_id FROM company_points WHERE company_id=?', [$company]);
        });
    }
    public static function invite(array $u, string $company, string $role): array
    {
        Auth::platform($u);
        if (
            !in_array($role, ['admin', 'dispatcher'], true) ||
            !Db::one('SELECT id FROM companies WHERE id=?', [$company])
        ) {
            throw new AppError('INVALID_INVITATION', '企业或角色无效');
        }
        $token = bin2hex(random_bytes(32));
        Db::insert('invitations', [
            'id' => Db::id(),
            'company_id' => $company,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires' => time() + 86400 * 7,
        ]);
        self::audit($u, 'member.invited', $company, ['role' => $role]);
        return [
            'url' => Env::get('APP_ORIGIN', 'http://localhost:5173') . '/profile?invite=' . $token,
            'expires' => time() + 86400 * 7,
        ];
    }
    public static function accept(array $u, string $token): array
    {
        return Db::tx(function () use ($u, $token) {
            $i = Db::one('SELECT * FROM invitations WHERE token_hash=?' . Db::lock(), [
                hash('sha256', $token),
            ]);
            if (!$i || $i['expires'] < time() || $i['used_by']) {
                throw new AppError('INVITATION_INVALID', '邀请已使用或过期', 409);
            }
            if (
                !Db::one('SELECT id FROM memberships WHERE user_id=? AND company_id=? AND role=?', [
                    $u['id'],
                    $i['company_id'],
                    $i['role'],
                ])
            ) {
                Db::insert('memberships', [
                    'id' => Db::id(),
                    'user_id' => $u['id'],
                    'company_id' => $i['company_id'],
                    'role' => $i['role'],
                ]);
            }
            Db::run('UPDATE invitations SET used_by=? WHERE id=?', [$u['id'], $i['id']]);
            self::audit($u, 'member.joined', $i['company_id'], ['role' => $i['role']]);
            return ['ok' => true];
        });
    }
    public static function audit(array $u, string $action, string $target, array $data): void
    {
        Db::insert('audit_logs', [
            'id' => Db::id(),
            'user_id' => $u['id'],
            'action' => $action,
            'target' => $target,
            'data' => Db::json($data),
            'created_at' => time(),
        ]);
    }
}
