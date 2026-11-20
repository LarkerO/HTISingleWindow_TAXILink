<?php
declare(strict_types=1);
namespace app\domain;

final class Media
{
    private static function config(): array
    {
        $saved = Db::one('SELECT config FROM system_settings WHERE id=?', ['cos']);
        if ($saved) {
            return SettingsSecret::open($saved['config']);
        }
        return [
            'bucket' => Env::get('COS_BUCKET'),
            'region' => Env::get('COS_REGION'),
            'publicUrl' => Env::get('COS_PUBLIC_URL'),
            'secretId' => Env::get('COS_SECRET_ID'),
            'secretKey' => Env::get('COS_SECRET_KEY'),
        ];
    }

    public static function settings(): array
    {
        $c = self::config();
        return [
            'configured' =>
                $c['bucket'] !== '' &&
                $c['region'] !== '' &&
                $c['secretId'] !== '' &&
                $c['secretKey'] !== '',
            'bucket' => $c['bucket'],
            'region' => $c['region'],
            'publicUrl' => $c['publicUrl'],
            'secretIdConfigured' => $c['secretId'] !== '',
            'secretKeyConfigured' => $c['secretKey'] !== '',
            'maxBytes' => 5 * 1024 * 1024,
        ];
    }

    public static function save(array $user, array $input): array
    {
        Auth::platform($user);
        $old = self::config();
        $c = [];
        foreach (['bucket', 'region', 'publicUrl', 'secretId', 'secretKey'] as $field) {
            if (!is_string($input[$field] ?? '') || strlen($input[$field] ?? '') > 2048) {
                throw new AppError('VALIDATION', 'COS 配置格式无效', 422);
            }
            $c[$field] = trim($input[$field] ?? '');
        }
        foreach (['secretId', 'secretKey'] as $field) {
            if ($c[$field] === '') {
                $c[$field] = $old[$field];
            }
        }
        if (
            !preg_match('/^[a-z0-9-]+-[0-9]+$/D', $c['bucket']) ||
            !preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $c['region']) ||
            !preg_match('/^[A-Za-z0-9]{10,256}$/D', $c['secretId']) ||
            !preg_match('/^[A-Za-z0-9]{10,256}$/D', $c['secretKey'])
        ) {
            throw new AppError(
                'VALIDATION',
                '请填写有效的 COS 存储桶、地域、SecretId 和 SecretKey',
                422,
            );
        }
        $c['publicUrl'] = rtrim(self::imageUrl($c['publicUrl']), '/');
        if (
            $c['publicUrl'] !== '' &&
            (parse_url($c['publicUrl'], PHP_URL_QUERY) !== null ||
                parse_url($c['publicUrl'], PHP_URL_FRAGMENT) !== null)
        ) {
            throw new AppError('VALIDATION', 'COS 公开域名不能包含查询参数或片段', 422);
        }
        $encrypted = SettingsSecret::seal($c);
        Db::tx(function () use ($encrypted, $user): void {
            Db::run('DELETE FROM system_settings WHERE id=?', ['cos']);
            Db::insert('system_settings', ['id' => 'cos', 'config' => $encrypted]);
            Admin::audit($user, 'media.settings', 'cos', ['updated' => true]);
        });
        return self::settings();
    }

    public static function imageUrl(mixed $value): string
    {
        if (
            !is_string($value) ||
            strlen($value) > 2048 ||
            ($value !== '' &&
                (!filter_var($value, FILTER_VALIDATE_URL) ||
                    parse_url($value, PHP_URL_SCHEME) !== 'https' ||
                    parse_url($value, PHP_URL_USER) !== null ||
                    parse_url($value, PHP_URL_PASS) !== null))
        ) {
            throw new AppError('VALIDATION', '图片地址必须是 HTTPS URL', 422);
        }
        return $value;
    }

    public static function authorization(
        string $host,
        string $path,
        string $secretId,
        string $secretKey,
        int $now,
    ): string {
        $period = $now - 60 . ';' . ($now + 600);
        $http = "put\n$path\n\nhost=" . rawurlencode($host) . "\n";
        $key = hash_hmac('sha1', $period, $secretKey);
        $signature = hash_hmac('sha1', "sha1\n$period\n" . sha1($http) . "\n", $key);
        return 'q-sign-algorithm=sha1&q-ak=' .
            rawurlencode($secretId) .
            '&q-sign-time=' .
            $period .
            '&q-key-time=' .
            $period .
            '&q-header-list=host&q-url-param-list=&q-signature=' .
            $signature;
    }

    public static function upload(array $user, string $purpose, string $company, mixed $file): array
    {
        if (!in_array($purpose, ['company', 'vehicle', 'announcement'], true)) {
            throw new AppError('VALIDATION', '图片用途无效', 422);
        }
        if ($purpose === 'vehicle') {
            Auth::company($user, $company, true);
        } else {
            Auth::platform($user);
        }
        if (!$file || !$file->isValid()) {
            throw new AppError('VALIDATION', '请选择图片文件', 422);
        }
        return self::uploadPath($user, $purpose, $file->getRealPath());
    }

    private static function uploadPath(array $user, string $purpose, string $path): array
    {
        if (filesize($path) < 1 || filesize($path) > 5 * 1024 * 1024) {
            throw new AppError('VALIDATION', '图片大小应在 1B–5MB 之间', 422);
        }
        $info = @getimagesize($path);
        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $mime = $info['mime'] ?? '';
        if (!isset($extensions[$mime]) || $info[0] > 8192 || $info[1] > 8192) {
            throw new AppError('VALIDATION', '仅支持 PNG、JPEG、WebP、GIF，长宽最多 8192px', 422);
        }
        $settings = self::settings();
        if (!$settings['configured']) {
            throw new AppError(
                'COS_NOT_CONFIGURED',
                '请先由平台管理员在“COS 图片存储”中保存配置',
                503,
            );
        }
        $bucket = $settings['bucket'];
        $region = $settings['region'];
        if (
            !preg_match('/^[a-z0-9-]+-[0-9]+$/D', $bucket) ||
            !preg_match('/^[a-z0-9-]+$/D', $region)
        ) {
            throw new AppError('COS_CONFIG_INVALID', 'COS 桶名或地域无效', 503);
        }
        $host = "$bucket.cos.$region.myqcloud.com";
        $key =
            'taxilink/' . $purpose . '/' . date('Y/m') . '/' . Db::id() . '.' . $extensions[$mime];
        $base = rtrim($settings['publicUrl'] ?: 'https://' . $host, '/');
        self::imageUrl($base);
        $credentials = self::config();
        $curl = curl_init('https://' . $host . '/' . $key);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => file_get_contents($path),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $mime,
                'Content-MD5: ' . base64_encode(md5_file($path, true)),
                'Authorization: ' .
                self::authorization(
                    $host,
                    '/' . $key,
                    $credentials['secretId'],
                    $credentials['secretKey'],
                    time(),
                ),
            ],
        ]);
        $result = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new AppError(
                'COS_UPLOAD_FAILED',
                'COS 上传失败，请检查凭据、桶权限和网络后重试',
                502,
            );
        }
        Admin::audit($user, 'media.upload', $key, [
            'purpose' => $purpose,
            'bytes' => filesize($path),
        ]);
        return ['url' => $base . '/' . $key, 'key' => $key];
    }
}
