<?php
declare(strict_types=1);
namespace app\domain;

final class Media
{
    public static function settings(?string $provider = null): array
    {
        return ObjectStorage::settings($provider);
    }
    public static function save(array $user, array $input): array
    {
        return ObjectStorage::save($user, $input);
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

    public static function upload(
        array $user,
        string $purpose,
        string $company,
        mixed $file,
        ?string $provider = null,
    ): array {
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
        return self::uploadPath($user, $purpose, $file->getRealPath(), $provider);
    }

    private static function uploadPath(
        array $user,
        string $purpose,
        string $path,
        ?string $provider = null,
    ): array {
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
        $provider = ObjectStorage::provider($provider ?? ObjectStorage::active());
        $settings = self::settings($provider);
        if (!$settings['configured']) {
            throw new AppError('COS_NOT_CONFIGURED', '请先由平台管理员配置所选对象存储', 503);
        }
        $key =
            'taxilink/' . $purpose . '/' . date('Y/m') . '/' . Db::id() . '.' . $extensions[$mime];
        $plan = ObjectStorage::plan(
            $provider,
            ObjectStorage::config($provider),
            $key,
            $mime,
            file_get_contents($path),
            time(),
        );
        $curl = curl_init($plan['url']);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $plan['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $plan['headers'],
            CURLOPT_POSTFIELDS => isset($plan['fields'])
                ? [...$plan['fields'], 'file' => new \CURLFile($path, $mime, basename($key))]
                : file_get_contents($path),
        ]);
        $result = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new AppError(
                'COS_UPLOAD_FAILED',
                '对象存储上传失败，请检查所选服务的凭据、桶权限和网络',
                502,
            );
        }
        Admin::audit($user, 'media.upload', $key, [
            'purpose' => $purpose,
            'provider' => $provider,
            'bytes' => filesize($path),
            'url' => $plan['publicUrl'],
        ]);
        return ['url' => $plan['publicUrl'], 'key' => $key, 'provider' => $provider];
    }
}
