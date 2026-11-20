<?php
declare(strict_types=1);
namespace app\domain;

final class Bridge
{
    public static function connectionStatus(): string
    {
        if (Env::demo()) {
            return 'demo';
        }
        $token = Env::get('GATEWAY_TOKEN');
        if (strlen($token) < 32) {
            return 'unavailable';
        }
        try {
            $status = Http::request(
                Env::get('GATEWAY_URL', 'http://127.0.0.1:8789') . '/status',
                null,
                ['Authorization: Bearer ' . $token],
                2,
            );
            return match ($status['connected'] ?? null) {
                true => 'connected',
                false => 'offline',
                default => 'unavailable',
            };
        } catch (AppError) {
            return 'unavailable';
        }
    }

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
            $messages = [
                'VEHICLE_MOVING' => '车辆正在移动，请先紧急停车并确认静止后重试',
                'VEHICLE_OCCUPIED' => '车上仍有乘客，必须全部下车后才能清除车辆',
                'PASSENGERS_DISEMBARKING' => '下车点仍有乘客，请等待全部下车后再关门',
                'BLOCKER_CHANGED' => '占用关系已变化或信息过期，请刷新订单后重新核验',
                'NOT_RETURNING' => '目标车辆已不处于回库阶段，不能按回库车清除',
                'VEHICLE_NOT_READY' => '车辆尚未就绪或已结束，请刷新订单状态',
                'UNKNOWN_COMMAND' => '游戏模组尚未支持此操作，请同步更新 TAXILink 模组',
            ];
            throw new AppError(
                $response['error']['code'] ?? 'MOD_ERROR',
                $messages[$response['error']['code'] ?? ''] ??
                    ($response['error']['message'] ?? '模组请求失败'),
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
