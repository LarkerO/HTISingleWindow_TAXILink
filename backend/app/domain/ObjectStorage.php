<?php
declare(strict_types=1);
namespace app\domain;

final class ObjectStorage
{
    public const PROVIDERS = [
        'cos' => '腾讯云 COS',
        'oss' => '阿里云 OSS',
        'qiniu' => '七牛云 Kodo',
        's3' => 'AWS S3',
    ];
    private const QINIU = [
        'z0' => 'upload.qiniup.com',
        'z1' => 'upload-z1.qiniup.com',
        'z2' => 'upload-z2.qiniup.com',
        'na0' => 'upload-na0.qiniup.com',
        'as0' => 'upload-as0.qiniup.com',
        'cn-east-2' => 'upload-cn-east-2.qiniup.com',
    ];

    public static function provider(string $provider): string
    {
        if (!isset(self::PROVIDERS[$provider])) {
            throw new AppError('VALIDATION', '不支持的对象存储服务', 422);
        }
        return $provider;
    }
    public static function active(): string
    {
        $value = Db::data(
            Db::one('SELECT config FROM system_settings WHERE id=?', ['media_active'])['config'] ??
                null,
        );
        return isset(self::PROVIDERS[$value['provider'] ?? '']) ? $value['provider'] : 'cos';
    }
    public static function config(string $provider): array
    {
        self::provider($provider);
        $saved = Db::one('SELECT config FROM system_settings WHERE id=?', [
            $provider === 'cos' ? 'cos' : 'media_' . $provider,
        ]);
        if ($saved) {
            return SettingsSecret::open($saved['config']);
        }
        $c = array_fill_keys(['bucket', 'region', 'publicUrl', 'secretId', 'secretKey'], '');
        if ($provider === 'cos') {
            foreach (
                [
                    'bucket' => 'BUCKET',
                    'region' => 'REGION',
                    'publicUrl' => 'PUBLIC_URL',
                    'secretId' => 'SECRET_ID',
                    'secretKey' => 'SECRET_KEY',
                ]
                as $field => $env
            ) {
                $c[$field] = Env::get('COS_' . $env);
            }
        }
        return $c;
    }
    public static function configured(array $c): bool
    {
        return $c['bucket'] !== '' &&
            $c['region'] !== '' &&
            $c['secretId'] !== '' &&
            $c['secretKey'] !== '';
    }
    public static function choices(): array
    {
        $items = [];
        foreach (self::PROVIDERS as $id => $name) {
            if (self::configured(self::config($id))) {
                $items[] = ['id' => $id, 'name' => $name];
            }
        }
        return ['activeProvider' => self::active(), 'items' => $items];
    }
    public static function settings(?string $provider = null): array
    {
        $provider = self::provider($provider ?? self::active());
        $c = self::config($provider);
        return [
            'provider' => $provider,
            'activeProvider' => self::active(),
            'configured' => self::configured($c),
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
        $provider = self::provider((string) ($input['provider'] ?? 'cos'));
        $old = self::config($provider);
        $c = [];
        foreach (['bucket', 'region', 'publicUrl', 'secretId', 'secretKey'] as $field) {
            if (!is_string($input[$field] ?? '') || strlen($input[$field] ?? '') > 2048) {
                throw new AppError('VALIDATION', '对象存储配置格式无效', 422);
            }
            $c[$field] = trim($input[$field] ?? '');
        }
        foreach (['secretId', 'secretKey'] as $field) {
            if ($c[$field] === '') {
                $c[$field] = $old[$field];
            }
        }
        $bucketPattern =
            $provider === 'cos' ? '/^[a-z0-9-]+-[0-9]+$/D' : '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D';
        if (
            !preg_match($bucketPattern, $c['bucket']) ||
            !preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $c['region']) ||
            !preg_match('/^[A-Za-z0-9_+-\/=]{10,256}$/D', $c['secretId']) ||
            !preg_match('/^[A-Za-z0-9_+-\/=]{10,256}$/D', $c['secretKey']) ||
            ($provider === 'qiniu' && !isset(self::QINIU[$c['region']]))
        ) {
            throw new AppError('VALIDATION', '请填写有效的存储桶、地域和访问凭据', 422);
        }
        $c['publicUrl'] = rtrim(Media::imageUrl($c['publicUrl']), '/');
        if (
            ($provider === 'qiniu' && $c['publicUrl'] === '') ||
            ($c['publicUrl'] !== '' &&
                (parse_url($c['publicUrl'], PHP_URL_QUERY) !== null ||
                    parse_url($c['publicUrl'], PHP_URL_FRAGMENT) !== null))
        ) {
            throw new AppError(
                'VALIDATION',
                '七牛云需填写公开 HTTPS 域名；域名不能带查询参数或片段',
                422,
            );
        }
        $encrypted = SettingsSecret::seal($c);
        Db::tx(function () use ($user, $provider, $input, $encrypted): void {
            $id = $provider === 'cos' ? 'cos' : 'media_' . $provider;
            Db::run('DELETE FROM system_settings WHERE id=?', [$id]);
            Db::insert('system_settings', ['id' => $id, 'config' => $encrypted]);
            if (($input['activate'] ?? false) === true) {
                Db::run('DELETE FROM system_settings WHERE id=?', ['media_active']);
                Db::insert('system_settings', [
                    'id' => 'media_active',
                    'config' => Db::json(['provider' => $provider]),
                ]);
            }
            Admin::audit($user, 'media.settings', $provider, [
                'updated' => true,
                'activated' => ($input['activate'] ?? false) === true,
            ]);
        });
        return self::settings($provider);
    }
    private static function hmac(string $key, string $value): string
    {
        return hash_hmac('sha256', $value, $key, true);
    }
    private static function base64url(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }
    public static function v4Signature(
        string $secret,
        string $stamp,
        string $region,
        string $service,
        string $canonical,
    ): string {
        $oss = $service === 'oss';
        $date = substr($stamp, 0, 8);
        $terminal = $oss ? 'aliyun_v4_request' : 'aws4_request';
        $algorithm = $oss ? 'OSS4-HMAC-SHA256' : 'AWS4-HMAC-SHA256';
        $scope = "$date/$region/$service/$terminal";
        $signing = self::hmac(($oss ? 'aliyun_v4' : 'AWS4') . $secret, $date);
        foreach ([$region, $service, $terminal] as $part) {
            $signing = self::hmac($signing, $part);
        }
        return hash_hmac(
            'sha256',
            "$algorithm\n$stamp\n$scope\n" . hash('sha256', $canonical),
            $signing,
        );
    }
    /** Pure request builder; no cloud request is made until Media validates an uploaded image. */
    public static function plan(
        string $provider,
        array $c,
        string $key,
        string $mime,
        string $bytes,
        int $now,
    ): array {
        self::provider($provider);
        $path = '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $md5 = base64_encode(md5($bytes, true));
        if ($provider === 'qiniu') {
            $policy = self::base64url(
                Db::json([
                    'scope' => $c['bucket'] . ':' . $key,
                    'deadline' => $now + 600,
                    'fsizeLimit' => 5 * 1024 * 1024,
                    'mimeLimit' => $mime,
                    'insertOnly' => 1,
                ]),
            );
            $token =
                $c['secretId'] .
                ':' .
                self::base64url(hash_hmac('sha1', $policy, $c['secretKey'], true)) .
                ':' .
                $policy;
            return [
                'url' => 'https://' . self::QINIU[$c['region']],
                'method' => 'POST',
                'headers' => [],
                'fields' => ['key' => $key, 'token' => $token],
                'publicUrl' => $c['publicUrl'] . $path,
            ];
        }
        $host = match ($provider) {
            'cos' => $c['bucket'] . '.cos.' . $c['region'] . '.myqcloud.com',
            'oss' => $c['bucket'] . '.oss-' . $c['region'] . '.aliyuncs.com',
            's3' => $c['bucket'] .
                '.s3.' .
                $c['region'] .
                (str_starts_with($c['region'], 'cn-') ? '.amazonaws.com.cn' : '.amazonaws.com'),
        };
        $headers = ['Content-Type: ' . $mime, 'Content-MD5: ' . $md5];
        if ($provider === 'cos') {
            $auth = Media::authorization($host, $path, $c['secretId'], $c['secretKey'], $now);
        } else {
            $stamp = gmdate('Ymd\THis\Z', $now);
            $date = substr($stamp, 0, 8);
            $oss = $provider === 'oss';
            $prefix = $oss ? 'x-oss' : 'x-amz';
            $payload = $oss ? 'UNSIGNED-PAYLOAD' : hash('sha256', $bytes);
            $canonicalHeaders =
                "content-md5:$md5\ncontent-type:$mime\n" .
                ($oss ? '' : "host:$host\n") .
                "$prefix-content-sha256:$payload\n$prefix-date:$stamp\n";
            $signed = $oss ? '' : 'content-md5;content-type;host;x-amz-content-sha256;x-amz-date';
            $canonical =
                "PUT\n" .
                ($oss ? '/' . $c['bucket'] : '') .
                "$path\n\n$canonicalHeaders\n$signed\n$payload";
            $service = $oss ? 'oss' : 's3';
            $terminal = $oss ? 'aliyun_v4_request' : 'aws4_request';
            $algorithm = $oss ? 'OSS4-HMAC-SHA256' : 'AWS4-HMAC-SHA256';
            $scope = "$date/{$c['region']}/$service/$terminal";
            $signature = self::v4Signature(
                $c['secretKey'],
                $stamp,
                $c['region'],
                $service,
                $canonical,
            );
            $auth =
                "$algorithm Credential={$c['secretId']}/$scope," .
                ($oss ? '' : "SignedHeaders=$signed,") .
                "Signature=$signature";
            $headers[] = "$prefix-date: $stamp";
            $headers[] = "$prefix-content-sha256: $payload";
        }
        $headers[] = 'Authorization: ' . $auth;
        return [
            'url' => 'https://' . $host . $path,
            'method' => 'PUT',
            'headers' => $headers,
            'publicUrl' => ($c['publicUrl'] ?: 'https://' . $host) . $path,
        ];
    }
}
