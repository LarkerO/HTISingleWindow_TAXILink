<?php
declare(strict_types=1);
namespace app\domain;

final class Media
{
    public static function settings(): array
    {
        return [
            'configured' =>
                Env::get('COS_BUCKET') !== '' &&
                Env::get('COS_REGION') !== '' &&
                Env::get('COS_SECRET_ID') !== '' &&
                Env::get('COS_SECRET_KEY') !== '',
            'bucket' => Env::get('COS_BUCKET'),
            'region' => Env::get('COS_REGION'),
            'publicUrl' => Env::get('COS_PUBLIC_URL'),
            'maxBytes' => 5 * 1024 * 1024,
        ];
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
            throw new AppError('COS_NOT_CONFIGURED', '请先在后端配置 COS 环境变量', 503);
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
                    Env::get('COS_SECRET_ID'),
                    Env::get('COS_SECRET_KEY'),
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
