<?php
declare(strict_types=1);
namespace app\domain;

final class Env
{
    private static bool $loaded = false;
    private static bool $installMode;
    public static function installationDir(): string
    {
        return dirname(__DIR__, 2) . '/installation';
    }
    public static function configured(): bool
    {
        return is_file(self::installationDir() . '/config.json') ||
            is_file(dirname(__DIR__, 2) . '/.env') || self::get('DB_DSN') !== '';
    }
    public static function installMode(): bool
    {
        self::boot();
        return self::$installMode;
    }
    public static function boot(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        self::$installMode = !self::configured();
        $installed = self::installationDir() . '/config.json';
        if (is_file($installed)) {
            foreach (json_decode(file_get_contents($installed), true, 32, JSON_THROW_ON_ERROR) as $key => $value) {
                putenv($key . '=' . $value);
            }
        }
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
    public static function listener(string $key, string $default): string
    {
        $address = self::get($key, $default);
        if (
            (self::get('APP_ENV') === 'testing' ||
                (self::installMode() && self::get('TAXILINK_LOCAL_INSTALL') === '1')) &&
            parse_url($address, PHP_URL_HOST) !== '127.0.0.1'
        ) {
            throw new \RuntimeException("测试安装要求 $key 绑定 127.0.0.1");
        }
        return $address;
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
