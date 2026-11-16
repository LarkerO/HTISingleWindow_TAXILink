<?php
declare(strict_types=1);
namespace app\domain;
final class RateLimit
{
    private static array $local = [];
    public static function check(string $key, int $limit): void
    {
        $minute = intdiv(time(), 60);
        $bucket = 'taxilink:rate:' . hash('sha256', $key) . ':' . $minute;
        if (Env::get('REDIS_URL') !== '') {
            $redis = new \Predis\Client(Env::get('REDIS_URL'));
            $count = $redis->eval(
                "local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],65) end; return n",
                1,
                $bucket,
            );
        } elseif (Env::get('APP_ENV') === 'testing') {
            $count = Db::tx(function () use ($bucket, $minute): int {
                Db::run('DELETE FROM rate_limits WHERE minute<?', [$minute]);
                Db::run(
                    'INSERT INTO rate_limits (id,minute,hits) VALUES (?,?,1) ON CONFLICT(id) DO UPDATE SET hits=hits+1',
                    [$bucket, $minute],
                );
                return (int) Db::one('SELECT hits FROM rate_limits WHERE id=?', [$bucket])['hits'];
            });
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
