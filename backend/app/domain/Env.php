<?php
declare(strict_types=1);
namespace app\domain;

final class Env
{
    private static bool $loaded = false;
    public static function boot(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $file = dirname(__DIR__, 2) . '/.env';
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (!preg_match('/^([A-Z_][A-Z_0-9]*)=(.*)$/', trim($line), $m)) {
                continue;
            }
            if (getenv($m[1]) === false) {
                putenv($m[1] . '=' . trim($m[2], "\"'"));
            }
        }
        if (
            self::get('APP_ENV', 'local') === 'production' &&
            (self::demo() || self::get('APP_SECURE_COOKIE') !== '1')
        ) {
            throw new \RuntimeException('生产环境必须关闭演示并启用安全 Cookie');
        }
    }
    public static function get(string $key, string $default = ''): string
    {
        return getenv($key) === false ? $default : (string) getenv($key);
    }
    public static function demo(): bool
    {
        return self::get('DEMO_MODE', '0') === '1';
    }
    public static function keyPath(string $key, string $default): string
    {
        $path = self::get($key, $default);
        return str_starts_with($path, '/') ||
            str_starts_with($path, '\\') ||
            preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            ? $path
            : dirname(__DIR__, 2) . '/' . $path;
    }
}
