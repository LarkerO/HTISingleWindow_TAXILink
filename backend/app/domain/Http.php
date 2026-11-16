<?php
declare(strict_types=1);
namespace app\domain;
final class Http
{
    public static function request(
        string $url,
        ?array $data = null,
        array $headers = [],
        int $timeout = 20,
    ): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => array_merge(
                ['Accept: application/json'],
                $data === null ? [] : ['Content-Type: application/json'],
                $headers,
            ),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        if ($data !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => Db::json($data)]);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $details = [];
        if ($raw === false && $errno !== 0) {
            $details['curl'] = $errno;
        }
        if ($raw === false && in_array($errno, [CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE], true)) {
            throw new AppError('UPSTREAM_TLS', '上游 HTTPS 证书验证失败，请检查 PHP 信任的 CA', 502, $details);
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status !== 0) {
            $details['http'] = $status;
        }
        if ($raw === false || $status >= 500 || $status === 0) {
            throw new AppError('UPSTREAM_UNCERTAIN', '服务暂不可用，正在核对请求状态', 503, $details);
        }
        $result = json_decode($raw, true, 64);
        if ($status >= 300) {
            $oauth = Portal::errorCode($result['error'] ?? null)
                ?? Portal::errorCode($result['data']['code'] ?? null);
            if ($oauth !== null) {
                $details['oauth'] = $oauth;
            }
            throw new AppError('UPSTREAM_REJECTED', '上游服务拒绝请求', 502, $details);
        }
        if (!is_array($result)) {
            throw new AppError('UPSTREAM_INVALID_RESPONSE', '上游返回的响应格式无效', 502, $details);
        }
        return $result;
    }
}
