<?php
declare(strict_types=1);
namespace app\domain;

final class Fare
{
    public static function defaults(): array
    {
        return [
            'mode' => 'fixed',
            'payment' => 'after',
            'baseMinor' => 500,
            'includedMeters' => 2000,
            'perKmMinor' => 200,
            'perMinuteMinor' => 0,
            'pickupFreeMeters' => 3000,
            'pickupPerKmMinor' => 100,
            'returnFreeMeters' => 3000,
            'returnPerKmMinor' => 100,
            'cancelMinor' => 0,
            'noShowMinor' => 0,
        ];
    }
    public static function validate(array $input): array
    {
        $r = array_replace(self::defaults(), $input);
        if (
            !in_array($r['mode'], ['fixed', 'metered'], true) ||
            !in_array($r['payment'], ['before', 'after', 'adjust'], true)
        ) {
            throw new AppError('INVALID_FARE', '计费或付款模式无效');
        }
        foreach (self::defaults() as $key => $value) {
            if (is_int($value) && (!is_int($r[$key]) || $r[$key] < 0 || $r[$key] > 100000000)) {
                throw new AppError('INVALID_FARE', '金额及里程必须为非负整数');
            }
        }
        return array_intersect_key($r, self::defaults());
    }
    public static function calculate(
        array $rules,
        float $distance,
        float $seconds,
        float $pickup,
        float $return,
    ): array {
        $r = self::validate($rules);
        $parts = [
            'baseMinor' => $r['baseMinor'],
            'distanceMinor' => (int) ceil(
                (max(0, $distance - $r['includedMeters']) * $r['perKmMinor']) / 1000,
            ),
            'timeMinor' => (int) ceil((max(0, $seconds) * $r['perMinuteMinor']) / 60),
            'pickupMinor' => (int) ceil(
                (max(0, $pickup - $r['pickupFreeMeters']) * $r['pickupPerKmMinor']) / 1000,
            ),
            'returnMinor' => (int) ceil(
                (max(0, $return - $r['returnFreeMeters']) * $r['returnPerKmMinor']) / 1000,
            ),
        ];
        return [
            'amountMinor' => array_sum($parts),
            'components' => $parts,
            'currency' => 'GAME',
            'demonstration' => true,
        ];
    }
}
