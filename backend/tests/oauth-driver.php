<?php
declare(strict_types=1);
// CLI-only contract driver. It is never loaded by webman routes.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'test') {
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';
ini_set('display_errors', 'stderr');
use app\domain\{Auth, Db};
Db::migrate();
while (($line = fgets(STDIN)) !== false) {
    try {
        $input = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (($input['action'] ?? '') === 'login') {
            Auth::login($input['profile']);
            $output = ['ok' => true];
        } elseif (($input['action'] ?? '') === 'stats') {
            $output = [
                'users' => Db::all(
                    'SELECT id,portal_sub,username,first_login,platform_admin FROM users',
                ),
                'sessions' => (int) Db::one('SELECT COUNT(*) AS n FROM sessions')['n'],
            ];
        } elseif (($input['action'] ?? '') === 'expire-state') {
            Db::run('UPDATE oauth_states SET expires=0');
            $output = ['ok' => true];
        } else {
            $headers = ['Host' => 'taxi.test', ...$input['headers'] ?? []];
            $raw = ($input['method'] ?? 'GET') . ' ' . $input['path'] . " HTTP/1.1\r\n";
            foreach ($headers as $key => $value) {
                $raw .= "$key: $value\r\n";
            }
            $request = new \support\Request($raw . "\r\n");
            $response = (new \app\controller\ApiController())->handle($request);
            $output = [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => $response->rawBody(),
                'setCookieCount' => preg_match_all('/^Set-Cookie:/mi', (string) $response),
            ];
        }
        echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo json_encode(['driverError' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    }
}
