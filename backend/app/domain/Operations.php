<?php
declare(strict_types=1);
namespace app\domain;
final class Operations
{
    public static function physical(array $user, array $order, string $action, array $input): array
    {
        if (!in_array($action, ['destroy', 'open_doors', 'close_doors', 'clear_blocker'], true)) {
            throw new AppError('INVALID_ACTION', '调度操作无效');
        }
        if ($order['company_id']) {
            Auth::company($user, $order['company_id'], true);
        } else {
            Auth::platform($user);
        }
        if ($action === 'clear_blocker') {
            $id = $input['targetOrderId'] ?? '';
            if (!is_string($id) || $id === $order['id']) {
                throw new AppError('INVALID_ACTION', '占用订单无效');
            }
            $target = Db::one('SELECT * FROM orders WHERE id=?', [$id]);
            if (!$target) {
                throw new AppError('NOT_FOUND', '占用订单不存在', 404);
            }
            if ($target['company_id']) {
                Auth::company($user, $target['company_id'], true);
            } else {
                Auth::platform($user);
            }
            $result = Bridge::command('taxi.clear_returning', [
                'orderId' => $order['id'],
                'targetOrderId' => $id,
            ]);
            Dispatch::ingest($id, 'reconcile.final', $result, time() * 1000);
            Admin::audit($user, 'order.clear_blocker', $order['id'], ['targetOrderId' => $id]);
        } elseif ($action === 'destroy') {
            $result = Bridge::command('taxi.destroy', ['orderId' => $order['id']]);
            Dispatch::ingest($order['id'], 'reconcile.final', $result, time() * 1000);
            Admin::audit($user, 'order.destroy', $order['id'], []);
        } else {
            $result = Bridge::command('taxi.doors', [
                'orderId' => $order['id'],
                'state' => $action === 'open_doors' ? 'open' : 'closed',
            ]);
            Admin::audit($user, 'order.' . $action, $order['id'], []);
        }
        return ['ok' => true, 'data' => $result];
    }
}
