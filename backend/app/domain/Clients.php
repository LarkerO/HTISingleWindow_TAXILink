<?php
declare(strict_types=1);
namespace app\domain;

/** All browser entry points share one identity database, with host-local cookies. */
final class Clients
{
    public static function passengers(): array
    {
        $saved = Db::data(
            Db::one('SELECT config FROM system_settings WHERE id=?', ['passenger_origins'])[
                'config'
            ] ?? null,
        );
        return array_values(
            array_unique(
                array_filter([
                    Env::get('APP_ORIGIN', 'http://localhost:5173'),
                    ...preg_split(
                        '/[\s,]+/',
                        Env::get('PASSENGER_ORIGINS'),
                        -1,
                        PREG_SPLIT_NO_EMPTY,
                    ),
                    ...$saved,
                ]),
            ),
        );
    }

    public static function allowed(string $origin): bool
    {
        return in_array(
            $origin,
            [...self::passengers(), Env::get('ADMIN_ORIGIN', 'http://localhost:5174')],
            true,
        );
    }

    public static function settings(): array
    {
        return [
            'origins' => self::passengers(),
            'primary' => Env::get('APP_ORIGIN', 'http://localhost:5173'),
        ];
    }

    public static function save(array $user, array $input): array
    {
        Auth::platform($user);
        $origins = $input['origins'] ?? null;
        if (!is_array($origins) || !array_is_list($origins) || count($origins) > 50) {
            throw new AppError('VALIDATION', '乘客端地址列表无效', 422);
        }
        foreach ($origins as $origin) {
            if (
                !is_string($origin) ||
                strlen($origin) > 250 ||
                !filter_var($origin, FILTER_VALIDATE_URL)
            ) {
                throw new AppError('VALIDATION', '请填写完整的乘客端源地址', 422);
            }
            $p = parse_url($origin);
            if (
                isset($p['user']) ||
                isset($p['pass']) ||
                isset($p['query']) ||
                isset($p['fragment']) ||
                ($p['path'] ?? '') !== '' ||
                !(
                    ($p['scheme'] ?? '') === 'https' ||
                    (($p['scheme'] ?? '') === 'http' &&
                        in_array($p['host'], ['localhost', '127.0.0.1'], true))
                )
            ) {
                throw new AppError(
                    'VALIDATION',
                    '地址须为 HTTPS 源地址，不带路径或末尾斜杠；本机允许 HTTP',
                    422,
                );
            }
        }
        Db::tx(function () use ($origins, $user): void {
            Db::run('DELETE FROM system_settings WHERE id=?', ['passenger_origins']);
            Db::insert('system_settings', [
                'id' => 'passenger_origins',
                'config' => Db::json(array_values(array_unique($origins))),
            ]);
            Admin::audit($user, 'clients.settings', 'passenger', ['origins' => $origins]);
        });
        return self::settings();
    }

    public static function loginOrigin(\support\Request $request): string
    {
        $origin = $request->get('clientOrigin');
        if ($origin === null) {
            $referer = parse_url((string) $request->header('referer'));
            $origin = isset($referer['scheme'], $referer['host'])
                ? $referer['scheme'] .
                    '://' .
                    $referer['host'] .
                    (isset($referer['port']) ? ':' . $referer['port'] : '')
                : Env::get('APP_ORIGIN', 'http://localhost:5173');
        }
        if (!is_string($origin) || !in_array($origin, self::passengers(), true)) {
            throw new AppError('ORIGIN_INVALID', '此乘客端尚未登记，请联系平台管理员', 403);
        }
        return $origin;
    }
}
