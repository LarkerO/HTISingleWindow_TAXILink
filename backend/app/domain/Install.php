<?php
declare(strict_types=1);
namespace app\domain;

use app\process\Sm2Wire;
use support\Request;
use support\Response;

final class Install
{
    private const CALLBACK = '/api/auth/callback';
    private const COOKIE = 'tl_install_oauth';

    public static function handles(Request $request): bool
    {
        return Env::installMode() || str_starts_with($request->path(), '/api/install/');
    }

    public static function issueCode(): string
    {
        return self::locked(function (): string {
            self::requireOpen();
            $code = bin2hex(random_bytes(24));
            self::write('ticket.json', [
                'hash' => hash('sha256', $code),
                'expires' => time() + 1800,
            ]);
            self::remove('pending.json');
            return $code;
        });
    }

    public static function handle(Request $request): mixed
    {
        $path = $request->path();
        $method = $request->method();
        if ($path === '/api/install/status' && $method === 'GET') {
            return [
                'state' => Env::installMode()
                    ? (Env::configured()
                        ? 'restart'
                        : 'setup')
                    : 'installed',
                'requirements' => Env::configured() ? [] : self::requirements(),
                'local' => self::local(),
            ];
        }
        self::requireOpen();
        if ($path === self::CALLBACK && $method === 'GET') {
            return self::locked(fn() => self::callback($request));
        }
        if ($path === '/api/install/start' && $method === 'POST') {
            return self::locked(fn() => self::start($request));
        }
        throw new AppError('INSTALL_REQUIRED', '请先在管理端 /install 完成安装', 503);
    }

    private static function requireOpen(): void
    {
        if (Env::configured()) {
            throw new AppError('INSTALL_CLOSED', '安装入口已关闭', 404);
        }
    }

    private static function local(): bool
    {
        return Env::get('TAXILINK_LOCAL_INSTALL') === '1';
    }

    private static function requirements(): array
    {
        static $cached = [],
            $checkedAt = 0;
        if ($checkedAt + 5 > time()) {
            return $cached;
        }
        $checkedAt = time();
        $directory = Env::installationDir();
        $openssl = self::openssl();
        try {
            self::command([$openssl, 'version']);
            $opensslReady = true;
        } catch (\Throwable) {
            $opensslReady = false;
        }
        return $cached = [
            'PHP 8.2+' => PHP_VERSION_ID >= 80200,
            self::local() ? 'PDO SQLite' : 'PDO MySQL' => in_array(
                self::local() ? 'sqlite' : 'mysql',
                \PDO::getAvailableDrivers(),
                true,
            ),
            'curl / mbstring' => extension_loaded('curl') && extension_loaded('mbstring'),
            'pcntl / posix (Linux)' =>
                PHP_OS_FAMILY === 'Windows' ||
                (extension_loaded('pcntl') && extension_loaded('posix')),
            'OpenSSL CLI' => $opensslReady,
            '安装目录可写' => is_writable(is_dir($directory) ? $directory : dirname($directory)),
            '运行目录可写' => is_writable(runtime_path()),
        ];
    }

    private static function openssl(): string
    {
        if (PHP_OS_FAMILY === 'Windows' && Env::get('TAXILINK_OPENSSL') === '') {
            $bundled = Env::get('ProgramFiles', 'C:\\Program Files') . '\\Git\\usr\\bin\\openssl.exe';
            if (is_file($bundled)) {
                return $bundled;
            }
        }
        return Env::get('TAXILINK_OPENSSL', 'openssl');
    }

    private static function start(Request $request): Response
    {
        self::requireOpen();
        try {
            $body = Db::data($request->rawBody());
        } catch (\Throwable) {
            throw new AppError('INSTALL_INPUT_INVALID', '安装请求格式无效', 422);
        }
        $ticket = self::read('ticket.json');
        $code = self::value($body, 'installCode');
        if (
            ($ticket['expires'] ?? 0) < time() ||
            !hash_equals($ticket['hash'] ?? '', hash('sha256', $code))
        ) {
            throw new AppError('INSTALL_CODE_INVALID', '安装码无效或已过期', 403);
        }
        if (in_array(false, self::requirements(), true)) {
            throw new AppError('REQUIREMENTS_FAILED', '请先修复未通过的安装要求', 422);
        }
        $local = self::local();
        $admin = self::origin(self::value($body, 'adminOrigin'), $local);
        $passenger = self::origin(self::value($body, 'passengerOrigin'), $local);
        if (
            parse_url($admin, PHP_URL_HOST) === parse_url($passenger, PHP_URL_HOST) ||
            $request->header('origin') !== $admin
        ) {
            throw new AppError(
                'INSTALL_ORIGIN_INVALID',
                '请从填写的管理端地址访问，且两端需使用不同主机名',
                422,
            );
        }
        $config =
            [
                'APP_ENV' => $local ? 'testing' : 'production',
                'DEMO_MODE' => '0',
                'APP_SECURE_COOKIE' => $local ? '0' : '1',
                'APP_ORIGIN' => $passenger,
                'ADMIN_ORIGIN' => $admin,
                'PORTAL_ISSUER' => self::origin(self::value($body, 'portalIssuer')),
                'PORTAL_CLIENT_ID' => self::value($body, 'clientId'),
                'PORTAL_CLIENT_SECRET' => self::value($body, 'clientSecret'),
                'PORTAL_REDIRECT_URI' => $passenger . self::CALLBACK,
                'PORTAL_ADMIN_REDIRECT_URI' => $admin . self::CALLBACK,
                'GATEWAY_TOKEN' => bin2hex(random_bytes(32)),
                'TAXILINK_OPENSSL' => self::openssl(),
                'TAXILINK_SERVER_ID' => self::value($body, 'serverId', '/^[a-zA-Z0-9_.-]{1,64}$/D'),
                'TAXILINK_DIMENSION' => self::value(
                    $body,
                    'dimension',
                    '#^[a-z0-9_./:-]{1,128}$#D',
                ),
            ] + self::storageConfig($body, $local);
        try {
            $pdo = self::database($config);
            if (!self::claim($config)) {
                self::requireEmpty($pdo);
            }
        } catch (AppError $e) {
            throw $e;
        } catch (\Throwable) {
            throw new AppError(
                'INSTALL_DB_FAILED',
                $local
                    ? 'SQLite 打开失败，请检查安装目录权限'
                    : 'MySQL 连接或读取失败，请检查地址、库名和账号权限',
                422,
            );
        }
        if (!$local) {
            self::checkRedis($config['REDIS_URL']);
        }
        $state = bin2hex(random_bytes(32));
        $verifier = Portal::verifier();
        self::write('pending.json', [
            'config' => $config,
            'ticket' => $ticket['hash'],
            'state' => hash('sha256', $state),
            'verifier' => $verifier,
            'expires' => min($ticket['expires'], time() + 600),
        ]);
        return json([
            'authorizeUrl' => Portal::authorize(
                $config['PORTAL_ISSUER'],
                $config['PORTAL_CLIENT_ID'],
                $config['PORTAL_ADMIN_REDIRECT_URI'],
                $state,
                $verifier,
            ),
        ])->withHeader('Set-Cookie', self::cookie($state, 600, !$local));
    }

    private static function storageConfig(array $body, bool $local): array
    {
        if ($local) {
            return [
                'DB_DSN' => 'sqlite:' . self::file('taxilink.sqlite'),
                'DB_USER' => '',
                'DB_PASSWORD' => '',
                'REDIS_URL' => '',
            ];
        }
        $redis = self::value($body, 'redisUrl');
        if (!preg_match('#^(?:tcp|tls)://#D', $redis)) {
            throw new AppError(
                'INSTALL_INPUT_INVALID',
                'Redis 地址须以 tcp:// 或 tls:// 开头',
                422,
            );
        }
        $host = self::value($body, 'dbHost', '/^[a-zA-Z0-9.-]+$/D');
        $database = self::value($body, 'dbName', '/^[a-zA-Z0-9_]+$/D');
        $port = filter_var($body['dbPort'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new AppError('INSTALL_INPUT_INVALID', 'MySQL 端口无效', 422);
        }
        return [
            'DB_DSN' => "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
            'DB_USER' => self::value($body, 'dbUser'),
            'DB_PASSWORD' => self::value($body, 'dbPassword'),
            'REDIS_URL' => $redis,
        ];
    }

    private static function checkRedis(string $redis): void
    {
        try {
            $client = new \Predis\Client($redis, [
                'parameters' => ['timeout' => 3, 'read_write_timeout' => 3],
            ]);
            $key = 'taxilink:install:' . bin2hex(random_bytes(16));
            if ($client->eval("return redis.call('SET',KEYS[1],'1','EX',5)", 1, $key) != 'OK') {
                throw new \RuntimeException();
            }
        } catch (\Throwable) {
            throw new AppError(
                'INSTALL_REDIS_FAILED',
                'Redis 连接或写入失败，请检查地址、认证和权限',
                422,
            );
        }
    }

    private static function callback(Request $request): Response
    {
        self::requireOpen();
        $pending = self::read('pending.json');
        $ticket = self::read('ticket.json');
        $state = $request->get('state');
        if (
            !is_string($state) ||
            $state === '' ||
            !hash_equals((string) $request->cookie(self::COOKIE, ''), $state) ||
            !hash_equals($pending['state'] ?? '', hash('sha256', $state)) ||
            !hash_equals($ticket['hash'] ?? '', $pending['ticket'] ?? '') ||
            ($pending['expires'] ?? 0) < time()
        ) {
            throw new AppError(
                'INSTALL_STATE_INVALID',
                '安装授权已失效，请返回 /install 重试',
                403,
            );
        }
        $config = $pending['config'];
        $secure = $config['APP_SECURE_COOKIE'] === '1';
        // Consume PKCE state before exchanging the code, even if Portal rejects it.
        if (!unlink(self::file('pending.json'))) {
            throw new AppError(
                'INSTALL_STORAGE_FAILED',
                '无法清除本次授权状态，请检查安装目录权限',
                503,
            );
        }
        $code = $request->get('code');
        $step = 'portal';
        try {
            if ($request->get('error') || !is_string($code) || $code === '') {
                $oauth = Portal::errorCode($request->get('error'));
                throw new AppError(
                    $request->get('error') ? 'OAUTH_CALLBACK_ERROR' : 'OAUTH_CODE_MISSING',
                    'Portal 回调未提供有效授权码',
                    400,
                    array_filter(['stage' => 'callback', 'oauth' => $oauth]),
                );
            }
            $profile = Portal::profile(
                $config['PORTAL_ISSUER'],
                $config['PORTAL_CLIENT_ID'],
                $config['PORTAL_CLIENT_SECRET'],
                $config['PORTAL_ADMIN_REDIRECT_URI'],
                $code,
                $pending['verifier'],
            );
            $step = 'profile';
            Auth::validateProfile($profile);
            $step = 'keys';
            $keys = self::generateKeys($config['TAXILINK_OPENSSL']);
            $config += $keys;
            $step = 'database';
            $pdo = self::database($config);
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
                self::lockMySql($pdo);
            }
            $claim = self::claim($config);
            if ($claim) {
                if (!hash_equals($claim['sub'], $profile['sub'])) {
                    $step = 'account';
                    throw new \RuntimeException();
                }
            } else {
                self::requireEmpty($pdo);
                // Preserve the approved identity across a partial migration or failed config write.
                self::write('claim.json', ['dsn' => $config['DB_DSN'], 'sub' => $profile['sub']]);
            }
            $session = Db::withConnection($pdo, function () use ($profile): string {
                Db::migrate();
                if (
                    Db::one('SELECT id FROM users WHERE portal_sub<>? OR platform_admin<>1', [
                        $profile['sub'],
                    ])
                ) {
                    throw new \RuntimeException();
                }
                return Auth::login($profile, $profile['sub']);
            });
            $config['PLATFORM_ADMIN_SUB'] = $profile['sub'];
            $step = 'publish';
            self::write('config.json', $config);
            self::remove('ticket.json');
            self::remove('claim.json');
            return redirect('/install')->withHeaders([
                'Set-Cookie' => [
                    self::cookie('', 0, $secure),
                    Auth::cookie('tl_session', $session, 7200, $secure),
                ],
            ]);
        } catch (\Throwable $error) {
            if ($step === 'portal' && $error instanceof AppError && $error->key === 'UPSTREAM_TLS') {
                $step = 'portal-tls';
            }
            if (isset($keys) && !Env::configured()) {
                self::removeKeys(dirname($keys['TAXILINK_GATEWAY_PRIVATE_KEY']));
            }
            $details = ['error' => $step];
            if ($error instanceof AppError && in_array($step, ['portal', 'portal-tls', 'profile'], true)) {
                $details += ['reason' => $error->key] + $error->details;
            }
            return redirect('/install?' . http_build_query($details))->withHeader(
                'Set-Cookie',
                self::cookie('', 0, $secure),
            );
        }
    }

    private static function lockMySql(\PDO $pdo): void
    {
        $lock =
            'taxilink-install:' .
            substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $query = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([$lock]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new \RuntimeException();
        }
    }

    private static function claim(array $config): array
    {
        $claim = self::read('claim.json');
        if ($claim && !hash_equals($claim['dsn'], $config['DB_DSN'])) {
            throw new AppError(
                'INSTALL_DB_CHANGED',
                '请使用上次初始化时填写的数据库地址和库名',
                422,
            );
        }
        return $claim;
    }

    private static function database(array $config): \PDO
    {
        return Db::connect($config['DB_DSN'], $config['DB_USER'], $config['DB_PASSWORD'], [
            \PDO::ATTR_TIMEOUT => 3,
        ]);
    }

    private static function requireEmpty(\PDO $pdo): void
    {
        $sql =
            $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "SELECT COUNT(*) FROM sqlite_master WHERE name NOT GLOB 'sqlite_*'"
                : 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()';
        if ((int) $pdo->query($sql)->fetchColumn() !== 0) {
            throw new AppError('INSTALL_DB_NOT_EMPTY', '请选择专用于 TAXILink 的空数据库', 422);
        }
    }

    private static function generateKeys(string $openssl): array
    {
        $dir = self::file('sm2-' . bin2hex(random_bytes(12)));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException();
        }
        try {
            return self::writeKeyPairs($openssl, $dir);
        } catch (\Throwable $e) {
            self::removeKeys($dir);
            throw $e;
        }
    }

    private static function writeKeyPairs(string $openssl, string $dir): array
    {
        foreach (['gateway', 'mod'] as $role) {
            $private = "$dir/taxilink-$role-private.pem";
            $public = "$dir/taxilink-$role-public.pem";
            self::command([
                $openssl,
                'genpkey',
                '-algorithm',
                'EC',
                '-pkeyopt',
                'ec_paramgen_curve:SM2',
                '-out',
                $private,
            ]);
            if (!chmod($private, 0600)) {
                throw new \RuntimeException();
            }
            self::command([$openssl, 'pkey', '-in', $private, '-pubout', '-out', $public]);
        }
        $gateway = new Sm2Wire(
            $openssl,
            "$dir/taxilink-gateway-private.pem",
            "$dir/taxilink-mod-public.pem",
        );
        $mod = new Sm2Wire(
            $openssl,
            "$dir/taxilink-mod-private.pem",
            "$dir/taxilink-gateway-public.pem",
        );
        $mod->verify('gateway', $gateway->sign('gateway', '{}'));
        $gateway->verify('mod', $mod->sign('mod', '{}'));
        return [
            'TAXILINK_GATEWAY_PRIVATE_KEY' => "$dir/taxilink-gateway-private.pem",
            'TAXILINK_MOD_PUBLIC_KEY' => "$dir/taxilink-mod-public.pem",
        ];
    }

    private static function command(array $args): void
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            $args,
            [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException();
        }
        $deadline = microtime(true) + 3;
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    if ($status['exitcode'] === 0) {
                        return;
                    }
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException();
        } finally {
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }

    private static function value(array $body, string $key, ?string $pattern = null): string
    {
        $value = $body[$key] ?? null;
        if (
            !is_string($value) ||
            $value === '' ||
            strlen($value) > 2048 ||
            preg_match('/[\x00-\x1f\x7f]/', $value) ||
            ($pattern && !preg_match($pattern, $value))
        ) {
            throw new AppError('INSTALL_INPUT_INVALID', "字段 $key 缺失或格式无效", 422);
        }
        return $value;
    }

    private static function origin(string $value, bool $local = false): string
    {
        $pattern = $local
            ? '#^http://(?:localhost|127\.0\.0\.1)(?::[0-9]+)?$#D'
            : '#^https://[a-z0-9.-]+(?::[0-9]+)?$#D';
        if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match($pattern, $value)) {
            throw new AppError(
                'INSTALL_INPUT_INVALID',
                $local
                    ? '测试站点地址仅支持 localhost 或 127.0.0.1 的 HTTP 根地址'
                    : '站点地址须为 HTTPS 根地址，例如 https://taxi.example.com',
                422,
            );
        }
        return $value;
    }

    private static function cookie(string $value, int $age, bool $secure): string
    {
        return self::COOKIE .
            "=$value; Path=" .
            self::CALLBACK .
            "; HttpOnly; SameSite=Lax; Max-Age=$age" .
            ($secure ? '; Secure' : '');
    }

    private static function file(string $name): string
    {
        return Env::installationDir() . '/' . $name;
    }

    private static function locked(callable $action): mixed
    {
        $dir = Env::installationDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new AppError('INSTALL_STORAGE_FAILED', '无法创建安装目录', 503);
        }
        $lock = fopen(self::file('install.lock'), 'c');
        if (!$lock) {
            throw new AppError('INSTALL_STORAGE_FAILED', '无法打开安装锁', 503);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new AppError('INSTALL_BUSY', '另一个安装请求正在执行，请稍后重试', 409);
            }
            return $action();
        } finally {
            fclose($lock);
        }
    }

    private static function read(string $name): array
    {
        $path = self::file($name);
        return is_file($path) ? Db::data(file_get_contents($path)) : [];
    }

    private static function remove(string $name): void
    {
        if (is_file(self::file($name))) {
            @unlink(self::file($name));
        }
    }

    private static function removeKeys(string $directory): void
    {
        foreach (glob($directory . '/*.pem') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }

    private static function write(string $name, array $data): void
    {
        $temp = tempnam(Env::installationDir(), 'setup-');
        if ($temp === false) {
            throw new \RuntimeException('无法创建安装配置');
        }
        try {
            if (!chmod($temp, 0600)) {
                throw new \RuntimeException('无法限制安装配置权限');
            }
            $encoded = Db::json($data);
            if (
                file_put_contents($temp, $encoded) !== strlen($encoded) ||
                !rename($temp, self::file($name))
            ) {
                throw new \RuntimeException('无法保存安装配置');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
