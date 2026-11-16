<?php
declare(strict_types=1);
namespace app\domain;

final class Portal
{
    public static function errorCode(mixed $error): ?string
    {
        return in_array($error, [
            'access_denied', 'invalid_request', 'invalid_client', 'invalid_grant',
            'unauthorized_client', 'unsupported_grant_type', 'unsupported_response_type',
            'invalid_scope', 'invalid_token', 'insufficient_scope', 'server_error',
            'temporarily_unavailable',
            'OAUTH_ACCESS_TOKEN_INVALID', 'OAUTH_AUTHORIZATION_CODE_INVALID',
            'OAUTH_CLIENT_AUTHENTICATION_FAILED', 'OAUTH_CLIENT_INVALID', 'OAUTH_CLIENT_NOT_FOUND',
            'OAUTH_GRANT_TYPE_INVALID', 'OAUTH_PKCE_INVALID', 'OAUTH_PKCE_REQUIRED',
            'OAUTH_REDIRECT_URI_INVALID', 'OAUTH_SCOPE_INVALID', 'OAUTH_TOKEN_REQUEST_INVALID',
        ], true) ? $error : null;
    }

    private static function request(string $stage, string $url, ?array $body, array $headers): array
    {
        try {
            return Http::request($url, $body, $headers);
        } catch (AppError $error) {
            throw new AppError($error->key, $error->getMessage(), $error->status, ['stage' => $stage] + $error->details);
        }
    }
    public static function verifier(): string
    {
        return self::base64url(random_bytes(48));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function authorize(
        string $issuer,
        string $client,
        string $callback,
        string $state,
        string $verifier,
    ): string {
        return rtrim($issuer, '/') .
            '/oauth/authorize?' .
            http_build_query([
                'response_type' => 'code',
                'client_id' => $client,
                'redirect_uri' => $callback,
                'scope' => 'profile hydroline',
                'state' => $state,
                'code_challenge' => self::base64url(hash('sha256', $verifier, true)),
                'code_challenge_method' => 'S256',
            ]);
    }

    public static function profile(
        string $issuer,
        string $client,
        string $secret,
        string $callback,
        string $code,
        string $verifier,
    ): array {
        $issuer = rtrim($issuer, '/');
        $token = self::request(
            'token',
            $issuer . '/api/oauth/token',
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $callback,
                'code_verifier' => $verifier,
            ],
            ['Authorization: Basic ' . base64_encode($client . ':' . $secret)],
        );
        if (
            !is_string($token['access_token'] ?? null) ||
            $token['access_token'] === '' ||
            strcasecmp((string) ($token['token_type'] ?? ''), 'Bearer') !== 0
        ) {
            throw new AppError('OAUTH_TOKEN_INVALID', 'Portal 返回的令牌格式无效', 502, ['stage' => 'token']);
        }
        return self::request('userinfo', $issuer . '/api/oauth/userinfo', null, [
            'Authorization: Bearer ' . $token['access_token'],
        ]);
    }
}
