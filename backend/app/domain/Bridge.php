<?php
declare(strict_types=1);
namespace app\domain;

final class Bridge
{
    public static function command(string $type, array $params = []): array
    {
        if (Env::demo()) {
            return Demo::command($type, $params);
        }
        $token = Env::get('GATEWAY_TOKEN');
        if (strlen($token) < 32) {
            throw new AppError('GATEWAY_NOT_CONFIGURED', '网关尚未配置', 503);
        }
        $response = Http::request(
            Env::get('GATEWAY_URL', 'http://127.0.0.1:8789') . '/command',
            [
                'type' => $type,
                'params' => $params,
                'dimension' => Env::get('TAXILINK_DIMENSION', 'minecraft/overworld'),
            ],
            ['Authorization: Bearer ' . $token],
        );
        if (!($response['ok'] ?? false)) {
            throw new AppError(
                $response['error']['code'] ?? 'MOD_ERROR',
                $response['error']['message'] ?? '模组请求失败',
                409,
            );
        }
        return $response['data'] ?? [];
    }
    public static function catalog(string $kind): array
    {
        if (!in_array($kind, ['depots', 'sidings', 'stations', 'platforms'], true)) {
            throw new AppError('INVALID_KIND', '目录类型无效');
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $items = [];
            $params = ['limit' => 100];
            try {
                do {
                    $d = self::command('mtr.' . $kind, $params);
                    array_push($items, ...$d['items']);
                    $params['offset'] = $d['nextOffset'];
                    $params['revision'] = $d['revision'];
                } while ($params['offset'] !== null);
                return $items;
            } catch (AppError $e) {
                if ($e->key !== 'CATALOG_CHANGED') {
                    throw $e;
                }
            }
        }
        throw new AppError('CATALOG_CHANGED', '目录正在变更，请稍后重试', 409);
    }
}
