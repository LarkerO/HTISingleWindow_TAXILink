<?php
declare(strict_types=1);
namespace app\domain;
final class RateLimit
{
    private static array $local = [];
    public static function check(string $key, int $limit): void
    {
        $bucket = 'taxilink:rate:' . hash('sha256', $key) . ':' . intdiv(time(), 60);
        if (Env::get('REDIS_URL') !== '') {
            $redis = new \Predis\Client(Env::get('REDIS_URL'));
            $count = $redis->eval(
                "local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],65) end; return n",
                1,
                $bucket,
            );
        } else {
            if (Env::get('APP_ENV') === 'production') {
                throw new AppError('REDIS_REQUIRED', '服务配置不完整', 503);
            }
            if (count(self::$local) > 5000) {
                self::$local = [];
            }
            $count = self::$local[$bucket] = (self::$local[$bucket] ?? 0) + 1;
        }
        if ($count > $limit) {
            throw new AppError('RATE_LIMITED', '操作太频繁，请稍后重试', 429);
        }
    }
}
