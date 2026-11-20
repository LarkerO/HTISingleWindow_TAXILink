<?php
declare(strict_types=1);
namespace app\domain;

/** Read-only previews: four in flight, a shared deadline, and a short cross-worker cache. */
final class QuoteRoutes
{
    public static function key(array $params): string
    {
        return hash(
            'sha256',
            Db::json([
                Env::get('GATEWAY_URL'),
                Env::get('TAXILINK_SERVER_ID'),
                Env::get('TAXILINK_DIMENSION'),
                Env::demo(),
                $params,
            ]),
        );
    }

    public static function fetch(array $requests, float $budget = 18): array
    {
        $results = [];
        $queue = [];
        Db::run('DELETE FROM route_cache WHERE expires<?', [time()]);
        foreach ($requests as $key => $params) {
            $cached = Db::one('SELECT config FROM route_cache WHERE id=? AND expires>?', [
                $key,
                time(),
            ]);
            if ($cached) {
                $results[$key] = Db::data($cached['config']);
            } elseif (Env::demo()) {
                try {
                    $results[$key] = Demo::command('taxi.quote', $params);
                } catch (AppError $e) {
                    $results[$key] = $e;
                }
            } else {
                $queue[$key] = $params;
            }
        }
        if (!$queue) {
            return $results;
        }
        $token = Env::get('GATEWAY_TOKEN');
        if (strlen($token) < 32) {
            throw new AppError('GATEWAY_NOT_CONFIGURED', '网关尚未配置', 503);
        }
        $multi = curl_multi_init();
        $active = [];
        $deadline = microtime(true) + $budget;
        try {
            do {
                while ($queue && count($active) < 4 && microtime(true) < $deadline) {
                    $key = array_key_first($queue);
                    $params = $queue[$key];
                    unset($queue[$key]);
                    $ch = curl_init(Env::get('GATEWAY_URL', 'http://127.0.0.1:8789') . '/command');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => Db::json([
                            'type' => 'taxi.quote',
                            'params' => $params,
                            'dimension' => Env::get('TAXILINK_DIMENSION', 'minecraft/overworld'),
                        ]),
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Accept: application/json',
                            'Authorization: Bearer ' . $token,
                        ],
                        CURLOPT_CONNECTTIMEOUT_MS => 1500,
                        CURLOPT_TIMEOUT_MS => max(
                            1,
                            (int) min(15500, ($deadline - microtime(true)) * 1000),
                        ),
                        CURLOPT_FOLLOWLOCATION => false,
                    ]);
                    if (
                        PHP_OS_FAMILY === 'Windows' &&
                        defined('CURLSSLOPT_NATIVE_CA') &&
                        !ini_get('curl.cainfo')
                    ) {
                        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
                    }
                    $active[spl_object_id($ch)] = [$ch, $key];
                    curl_multi_add_handle($multi, $ch);
                }
                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
                if ($status !== CURLM_OK) {
                    throw new AppError('QUOTE_UNAVAILABLE', '报价连接失败，请稍后重试', 503);
                }
                while ($info = curl_multi_info_read($multi)) {
                    $ch = $info['handle'];
                    [, $key] = $active[spl_object_id($ch)];
                    $response = json_decode((string) curl_multi_getcontent($ch), true, 64);
                    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    if ($info['result'] !== CURLE_OK || $http !== 200) {
                        $results[$key] = new AppError(
                            'QUOTE_UNAVAILABLE',
                            '报价服务响应超时或暂不可用，请稍后刷新报价',
                            503,
                        );
                    } elseif (!is_array($response) || !is_bool($response['ok'] ?? null)) {
                        $results[$key] = new AppError(
                            'UPSTREAM_INVALID_RESPONSE',
                            '报价服务返回了无效响应，请稍后重试',
                            502,
                        );
                    } elseif (!$response['ok']) {
                        $error = is_array($response['error'] ?? null) ? $response['error'] : [];
                        $code = $error['code'] ?? 'MOD_ERROR';
                        if (!is_string($code) || !preg_match('/^[A-Z0-9_]{1,64}$/D', $code)) {
                            $code = 'UPSTREAM_INVALID_RESPONSE';
                        }
                        if ($code === 'QUOTE_TIMEOUT') {
                            $code = 'QUOTE_UPSTREAM_TIMEOUT';
                        }
                        $message = is_string($error['message'] ?? null)
                            ? $error['message']
                            : '模组报价失败';
                        $results[$key] = new AppError($code, $message, 409);
                    } else {
                        $route = $response['data'] ?? [];
                        $results[$key] = self::validate($route);
                        if (is_array($results[$key])) {
                            $upsert =
                                Db::sqlite() || Db::pgsql()
                                    ? 'ON CONFLICT(id) DO UPDATE SET config=excluded.config, expires=excluded.expires'
                                    : 'ON DUPLICATE KEY UPDATE config=VALUES(config), expires=VALUES(expires)';
                            Db::run(
                                'INSERT INTO route_cache (id,config,expires) VALUES (?,?,?) ' .
                                    $upsert,
                                [$key, Db::json($route), time() + 20],
                            );
                        }
                    }
                    curl_multi_remove_handle($multi, $ch);
                    unset($active[spl_object_id($ch)]);
                }
                if ($active && microtime(true) < $deadline) {
                    if (
                        curl_multi_select(
                            $multi,
                            min(0.1, max(0.001, $deadline - microtime(true))),
                        ) === -1
                    ) {
                        usleep(1000);
                    }
                }
            } while (($queue || $active) && microtime(true) < $deadline);
        } finally {
            foreach ($active as [$ch, $key]) {
                curl_multi_remove_handle($multi, $ch);
                $results[$key] = new AppError(
                    'QUOTE_TIMEOUT',
                    '部分线路报价尚未完成，请刷新报价重试',
                    503,
                );
            }
            curl_multi_close($multi);
        }
        foreach ($queue as $key => $_) {
            $results[$key] = new AppError(
                'QUOTE_TIMEOUT',
                '部分线路报价尚未完成，请刷新报价重试',
                503,
            );
        }
        return $results;
    }

    private static function validate(mixed $route): array|AppError
    {
        foreach (
            ['distanceMeters', 'estimatedSeconds', 'pickupDistanceMeters', 'returnDistanceMeters']
            as $field
        ) {
            if (!is_array($route) || !isset($route[$field])) {
                return new AppError('MOD_UPGRADE_REQUIRED', '模组需要支持完整报价里程', 409);
            }
            if (
                !is_numeric($route[$field]) ||
                !is_finite((float) $route[$field]) ||
                $route[$field] < 0
            ) {
                return new AppError('UPSTREAM_INVALID_RESPONSE', '报价里程数据无效', 502);
            }
        }
        return $route;
    }
}
