<?php
declare(strict_types=1);
namespace app\process;
use app\domain\{Db, Env, SystemLog};
use Workerman\{Worker, Timer};
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\{Request, Response};

/** Authenticated SM2 connection owner; private HTTP RPC remains on loopback. */
final class ModGateway
{
    private ?TcpConnection $mod = null;
    private array $authorized = [];
    private array $pending = [];
    private Sm2Wire $wire;
    public function onWorkerStart(): void
    {
        $this->wire = new Sm2Wire(
            Env::get('TAXILINK_OPENSSL', 'openssl'),
            Env::keyPath('TAXILINK_GATEWAY_PRIVATE_KEY', 'keys/gateway-private.pem'),
            Env::keyPath('TAXILINK_MOD_PUBLIC_KEY', 'keys/mod-public.pem'),
        );
        if (strlen(Env::get('GATEWAY_TOKEN')) < 32) {
            throw new \RuntimeException('请配置至少 32 字符的 GATEWAY_TOKEN');
        }
        $port = filter_var(Env::get('GATEWAY_RPC_PORT', '8789'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new \RuntimeException('GATEWAY_RPC_PORT 必须是有效端口');
        }
        $http = new Worker('http://127.0.0.1:' . $port);
        $http->onMessage = function (TcpConnection $c, Request $r): void {
            if (
                !hash_equals(
                    'Bearer ' . Env::get('GATEWAY_TOKEN'),
                    (string) $r->header('authorization'),
                )
            ) {
                $this->reply($c, 401, ['error' => 'UNAUTHORIZED']);
                return;
            }
            if ($r->method() === 'GET' && $r->path() === '/status') {
                $this->reply($c, 200, ['connected' => $this->mod !== null]);
                return;
            }
            if ($r->method() !== 'POST' || $r->path() !== '/command') {
                $this->reply($c, 404, ['error' => 'NOT_FOUND']);
                return;
            }
            if (!$this->mod) {
                $this->reply($c, 503, ['error' => 'MOD_OFFLINE']);
                return;
            }
            try {
                if (strlen($r->rawBody()) > 60000 || count($this->pending) >= 64) {
                    throw new \RuntimeException('请求过大或过多');
                }
                $input = json_decode(
                    $r->rawBody(),
                    true,
                    32,
                    JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
                );
                $allowed = [
                    'taxi.quote',
                    'taxi.create',
                    'taxi.status',
                    'taxi.geometry',
                    'taxi.logs',
                    'taxi.list',
                    'taxi.depart',
                    'taxi.destroy',
                    'taxi.passengers',
                    'taxi.control',
                    'mtr.depots',
                    'mtr.sidings',
                    'mtr.stations',
                    'mtr.platforms',
                    'taxi.depots.add',
                    'taxi.depots.remove',
                ];
                if (
                    !in_array($input['type'] ?? '', $allowed, true) ||
                    ($input['dimension'] ?? '') !==
                        Env::get('TAXILINK_DIMENSION', 'minecraft/overworld')
                ) {
                    throw new \RuntimeException('命令或维度无效');
                }
                $id = Db::id();
                $frame = [
                    'v' => 1,
                    'type' => $input['type'],
                    'serverId' => Env::get('TAXILINK_SERVER_ID', 'taxilink-01'),
                    'requestId' => $id,
                    'dimension' => $input['dimension'],
                    'params' => $input['params'] ?: new \stdClass(),
                ];
                $payload = $this->wire->sign('gateway', Db::json($frame));
                $started = hrtime(true);
                $timer = Timer::add(15, function () use ($id): void {
                    $this->finish($id, 504, ['error' => 'MOD_RESPONSE_TIMEOUT'], 'timeout');
                }, [], false);
                $this->pending[$id] = [$c, $timer, $started, $frame['type'], $this->mod->id];
                if ($this->mod->send($payload) === false) {
                    $this->finish($id, 503, ['error' => 'MOD_SEND_FAILED'], 'send_failed');
                    return;
                }
                SystemLog::record('mod', 'command_sent', $this->mod->id, $id, $frame['type']);
            } catch (\Throwable $e) {
                $this->reply($c, 400, ['error' => 'INVALID_COMMAND']);
            }
        };
        $http->onClose = function (TcpConnection $c): void {
            foreach ($this->pending as $id => [$waiting]) {
                if ($waiting === $c) {
                    $this->pending[$id][0] = null;
                }
            }
        };
        $http->listen();
    }
    public function onConnect(TcpConnection $c): void
    {
        $c->maxPackageSize = 100000;
        Timer::add(
            5,
            function () use ($c): void {
                if ($this->mod !== $c) {
                    if (isset($this->authorized[$c->id])) {
                        SystemLog::record('mod', 'connection_rejected', $c->id, result: 'subscription_timeout');
                    }
                    $c->close();
                }
            },
            [],
            false,
        );
    }
    public function onWebSocketConnect(TcpConnection $c, Request $r): void
    {
        try {
            $proof = json_decode(
                $this->wire->verify('handshake', (string) $r->header('x-taxilink-proof')),
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
            if (
                $r->path() !== '/taxilink' ||
                ($proof['serverId'] ?? '') !== Env::get('TAXILINK_SERVER_ID', 'taxilink-01')
            ) {
                throw new \RuntimeException();
            }
            $this->authorized[$c->id] = true;
        } catch (\Throwable) {
            SystemLog::record('mod', 'connection_rejected', $c->id, result: 'handshake_invalid');
            $c->close();
        }
    }
    public function onMessage(TcpConnection $c, string $raw): void
    {
        try {
            if (!isset($this->authorized[$c->id])) {
                throw new \RuntimeException();
            }
            $m = json_decode(
                $this->wire->verify('mod', $raw),
                true,
                64,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
            if (
                ($m['v'] ?? 0) !== 1 ||
                ($m['serverId'] ?? '') !== Env::get('TAXILINK_SERVER_ID', 'taxilink-01')
            ) {
                throw new \RuntimeException();
            }
            if (($m['type'] ?? '') === 'subscribe') {
                if (
                    ($m['topics'] ?? []) !== [
                        'taxilink.commands.' . Env::get('TAXILINK_SERVER_ID', 'taxilink-01'),
                    ]
                ) {
                    throw new \RuntimeException();
                }
                if ($this->mod && $this->mod !== $c) {
                    $this->mod->close();
                }
                $this->mod = $c;
                $c->send($this->wire->sign('gateway', Db::json(['type' => 'subscribed'])));
                SystemLog::record('mod', 'connected', $c->id);
                return;
            }
            if ($this->mod !== $c) {
                throw new \RuntimeException();
            }
            if (($m['type'] ?? '') === 'response' && isset($this->pending[$m['requestId'] ?? ''])) {
                $this->finish($m['requestId'], 200, $m, ($m['ok'] ?? false) === true ? 'success' : 'mod_error');
            } elseif (
                str_starts_with($m['type'] ?? '', 'ride.') ||
                ($m['type'] ?? '') === 'taxi.telemetry'
            ) {
                Db::insert('mod_inbox', [
                    'id' => Db::id(),
                    'data' => Db::json($m),
                    'processed' => 0,
                    'created_at' => (int) ($m['timestamp'] ?? time() * 1000),
                ]);
            }
        } catch (\Throwable) {
            SystemLog::record('mod', $this->mod === $c ? 'message_rejected' : 'connection_rejected',
                $c->id, result: 'message_invalid');
            $c->close();
        }
    }
    public function onClose(TcpConnection $c): void
    {
        unset($this->authorized[$c->id]);
        if ($this->mod === $c) {
            $this->mod = null;
            SystemLog::record('mod', 'disconnected', $c->id);
            foreach (array_keys($this->pending) as $id) {
                $this->finish($id, 503, ['error' => 'MOD_DISCONNECTED'], 'disconnected');
            }
        }
    }
    private function finish(string $id, int $status, array $response, string $result): void
    {
        if (!isset($this->pending[$id])) {
            return;
        }
        [$waiting, $timer, $started, $command, $connection] = $this->pending[$id];
        unset($this->pending[$id]);
        $duration = intdiv(hrtime(true) - $started, 1_000_000);
        Timer::del($timer);
        SystemLog::record('mod', 'command_finished', $connection, $id, $command, $duration, $result);
        if ($waiting !== null) {
            $this->reply($waiting, $status, $response);
        }
    }
    private function reply(TcpConnection $c, int $status, array $body): void
    {
        $c->close(
            new Response(
                $status,
                ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
                Db::json($body),
            ),
        );
    }
}
