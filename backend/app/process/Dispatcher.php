<?php
declare(strict_types=1);
namespace app\process;
use app\domain\Dispatch;
use Workerman\Timer;
final class Dispatcher
{
    private mixed $lock;
    public function onWorkerStart(): void
    {
        $this->lock = fopen(runtime_path('dispatcher.lock'), 'c');
        if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('调度进程只能运行一个实例');
        }
        Timer::add(2, function (): void {
            try {
                Dispatch::tick();
            } catch (\Throwable $e) {
                \support\Log::error('调度失败：' . $e->getMessage());
            }
        });
    }
}
