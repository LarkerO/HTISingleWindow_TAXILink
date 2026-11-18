<?php
declare(strict_types=1);
namespace app\process;
use app\domain\{Cashier, Db, Env};
use Workerman\Timer;
final class CashierReconciler
{
    private bool $running = false;
    public function onWorkerStart(): void
    {
        Timer::add(10, function (): void {
            if (Env::demo() || $this->running) {
                return;
            }
            $this->running = true;
            try {
                foreach (
                    Db::all(
                        "SELECT DISTINCT t.order_id FROM cashier_trades t JOIN orders o ON o.id=t.order_id WHERE t.status IN ('creating','pending','processing') OR t.refund_id IS NOT NULL OR (t.status='paid' AND o.state IN ('COMPLETED','CANCELLED','FAILED') AND o.paid>o.amount)",
                    )
                    as $row
                ) {
                    try {
                        Cashier::syncOrder($row['order_id']);
                    } catch (\Throwable $e) {
                        \support\Log::warning(
                            '收银台对账失败：' . $row['order_id'] . ' ' . $e->getMessage(),
                        );
                    }
                }
            } finally {
                $this->running = false;
            }
        });
    }
}
