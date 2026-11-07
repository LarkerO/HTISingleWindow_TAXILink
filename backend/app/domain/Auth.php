<?php
declare(strict_types=1);
namespace app\domain;
use support\Request;

final class Auth
{
    public static function session(Request $request, bool $required = true): ?array
    {
        $token = (string) $request->cookie('tl_session', '');
        $row = $token
            ? Db::one(
                'SELECT s.id AS session_id,s.csrf,u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.expires>?',
                [hash('sha256', $token), time()],
            )
            : null;
        if (!$row && $required) {
            throw new AppError('LOGIN_REQUIRED', '请先登录', 401);
        }
        return $row;
    }
    public static function csrf(Request $request, array $user): void
    {
        if (!hash_equals($user['csrf'], (string) $request->header('x-csrf-token'))) {
            throw new AppError('CSRF_INVALID', '页面已过期，请刷新后重试', 403);
        }
        $origin = $request->header('origin');
        if (
            $origin &&
            !in_array(
                $origin,
                [
                    Env::get('APP_ORIGIN', 'http://localhost:5173'),
                    Env::get('ADMIN_ORIGIN', 'http://localhost:5174'),
                ],
                true,
            )
        ) {
            throw new AppError('ORIGIN_INVALID', '请求来源无效', 403);
        }
    }
    public static function cookie(string $name, string $value, int $maxAge): string
    {
        return "$name=$value; Path=/; HttpOnly; SameSite=Lax; Max-Age=$maxAge" .
            (Env::get('APP_SECURE_COOKIE') === '1' ? '; Secure' : '');
    }
    public static function login(array $profile): string
    {
        if (!is_string($profile['sub'] ?? null) || !$profile['sub']) {
            throw new AppError('PROFILE_INVALID', '账号资料缺少身份标识', 502);
        }
        $user = Db::one('SELECT * FROM users WHERE portal_sub=?', [$profile['sub']]);
        if (!$user) {
            $user = [
                'id' => Db::id(),
                'portal_sub' => $profile['sub'],
                'username' => $profile['preferred_username'] ?? ($profile['name'] ?? '乘客'),
                'avatar' => $profile['picture'] ?? null,
                'first_login' => time(),
                'platform_admin' =>
                    (int) (Env::get('PLATFORM_ADMIN_SUB') !== '' &&
                        hash_equals(Env::get('PLATFORM_ADMIN_SUB'), $profile['sub'])),
            ];
            Db::insert('users', $user);
        } else {
            Db::run('UPDATE users SET username=?,avatar=? WHERE id=?', [
                $profile['preferred_username'] ?? ($profile['name'] ?? $user['username']),
                $profile['picture'] ?? null,
                $user['id'],
            ]);
        }
        $token = bin2hex(random_bytes(32));
        Db::insert('sessions', [
            'id' => hash('sha256', $token),
            'user_id' => $user['id'],
            'csrf' => Db::id(),
            'expires' => time() + 7200,
        ]);
        return $token;
    }
    public static function publicUser(array $user): array
    {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'avatar' => $user['avatar'],
            'firstLogin' => (int) $user['first_login'],
            'platformAdmin' => (bool) $user['platform_admin'],
            'csrf' => $user['csrf'],
            'memberships' => Db::all(
                'SELECT m.company_id,m.role,c.name AS company_name FROM memberships m JOIN companies c ON c.id=m.company_id WHERE m.user_id=?',
                [$user['id']],
            ),
        ];
    }
    public static function company(array $user, string $company, bool $write = false): void
    {
        if ($user['platform_admin']) {
            return;
        }
        $roles = Db::all('SELECT role FROM memberships WHERE user_id=? AND company_id=?', [
            $user['id'],
            $company,
        ]);
        if (!$roles || ($write && !array_intersect(array_column($roles, 'role'), ['admin']))) {
            throw new AppError('FORBIDDEN', '无权访问该企业', 403);
        }
    }
    public static function platform(array $user): void
    {
        if (!$user['platform_admin']) {
            throw new AppError('FORBIDDEN', '需要平台管理员权限', 403);
        }
    }
    public static function order(array $user, string $id, bool $ownerOnly = true): array
    {
        $order = Db::one('SELECT * FROM orders WHERE id=?', [$id]);
        if (!$order) {
            throw new AppError('NOT_FOUND', '订单不存在', 404);
        }
        if ($order['user_id'] !== $user['id']) {
            if ($ownerOnly) {
                throw new AppError('FORBIDDEN', '无权操作该订单', 403);
            }
            self::company($user, (string) $order['company_id']);
        }
        return $order;
    }
}
