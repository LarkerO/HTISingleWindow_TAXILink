<?php
declare(strict_types=1);
namespace app\domain;

final class SettingsSecret
{
    private static function key(bool $create): string
    {
        $path = Env::keyPath('SETTINGS_KEY_FILE', 'installation/settings.key');
        if (!is_file($path) && $create) {
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new AppError(
                    'SETTINGS_KEY_UNAVAILABLE',
                    '无法创建设置密钥目录，请检查后端写入权限',
                    503,
                );
            }
        }
        $file = @fopen($path, $create ? 'c+b' : 'rb');
        if (!$file || !flock($file, $create ? LOCK_EX : LOCK_SH)) {
            if ($file) {
                fclose($file);
            }
            throw new AppError(
                'SETTINGS_KEY_UNAVAILABLE',
                '无法读取设置密钥，请检查权限或恢复备份',
                503,
            );
        }
        try {
            $key = stream_get_contents($file);
            if ($key === '' && $create) {
                @chmod($path, 0600);
                $key = random_bytes(32);
                if (fwrite($file, $key) !== 32 || !fflush($file)) {
                    throw new AppError('SETTINGS_KEY_UNAVAILABLE', '设置密钥写入失败', 503);
                }
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
        if ($key === false || strlen($key) !== 32) {
            throw new AppError(
                'SETTINGS_KEY_UNAVAILABLE',
                '无法读取设置密钥，请恢复 installation/settings.key 备份',
                503,
            );
        }
        return $key;
    }

    public static function seal(array $value): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new AppError(
                'SETTINGS_ENCRYPTION_UNAVAILABLE',
                '请启用 PHP OpenSSL 扩展后保存 COS 设置',
                503,
            );
        }
        $iv = random_bytes(12);
        $cipher = openssl_encrypt(
            Db::json($value),
            'aes-256-gcm',
            self::key(true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );
        if ($cipher === false) {
            throw new AppError('SETTINGS_ENCRYPTION_FAILED', '设置加密失败', 503);
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function open(string $value): array
    {
        if (!function_exists('openssl_decrypt')) {
            throw new AppError(
                'SETTINGS_ENCRYPTION_UNAVAILABLE',
                '请启用 PHP OpenSSL 扩展读取 COS 设置',
                503,
            );
        }
        $raw = base64_decode($value, true);
        $plain =
            $raw !== false && strlen($raw) > 28
                ? openssl_decrypt(
                    substr($raw, 28),
                    'aes-256-gcm',
                    self::key(false),
                    OPENSSL_RAW_DATA,
                    substr($raw, 0, 12),
                    substr($raw, 12, 16),
                )
                : false;
        if ($plain === false) {
            throw new AppError(
                'SETTINGS_DECRYPTION_FAILED',
                '设置无法解密，请恢复配套设置密钥',
                503,
            );
        }
        return Db::data($plain);
    }
}
