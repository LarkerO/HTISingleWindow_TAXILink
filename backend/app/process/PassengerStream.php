<?php
declare(strict_types=1);
namespace app\process;
use app\domain\{Booking, Db, Env};
use Workerman\{Worker, Timer};
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
final class PassengerStream
{
    private array $clients = [];
    public function onWorkerStart(): void
    {
        Timer::add(2, function (): void {
            foreach ($this->clients as $id => [$connection, $session]) {
                if (
                    !Db::one('SELECT id FROM sessions WHERE id=? AND expires>?', [
                        $session['id'],
                        time(),
                    ])
                ) {
                    $connection->close();
                    continue;
                }
                if ($connection->getSendBufferQueueSize() > 262144) {
                    $connection->close();
                    continue;
                }
                $orders = Db::all(
                    'SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 30',
                    [$session['user_id']],
                );
                $connection->send(
                    Db::json([
                        'type' => 'orders.snapshot',
                        'orders' => array_map(Booking::view(...), $orders),
                        'sampledAt' => time(),
                    ]),
                );
            }
        });
    }
    public function onConnect(TcpConnection $c): void
    {
        $c->maxPackageSize = 4096;
        Timer::add(
            5,
            function () use ($c): void {
                if (!isset($this->clients[$c->id])) {
                    $c->close();
                }
            },
            [],
            false,
        );
    }
    public function onWebSocketConnect(TcpConnection $c, Request $r): void
    {
        $origin = (string) $r->header('origin');
        $session = Db::one('SELECT * FROM sessions WHERE id=? AND expires>?', [
            hash('sha256', (string) $r->cookie('tl_session', '')),
            time(),
        ]);
        if (
            !$session ||
            $r->path() !== '/ws' ||
            !in_array(
                $origin,
                [
                    Env::get('APP_ORIGIN', 'http://localhost:5173'),
                    Env::get('ADMIN_ORIGIN', 'http://localhost:5174'),
                ],
                true,
            )
        ) {
            $c->close();
            return;
        }
        $this->clients[$c->id] = [$c, $session];
    }
    public function onMessage(TcpConnection $c, string $raw): void
    {
        if ($raw === 'ping') {
            $c->send('{"type":"pong"}');
        }
    }
    public function onClose(TcpConnection $c): void
    {
        unset($this->clients[$c->id]);
    }
}
