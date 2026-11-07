<?php
declare(strict_types=1);
namespace app\domain;

final class Demo
{
    public static function seed(): void
    {
        if (Db::one('SELECT id FROM companies LIMIT 1')) {
            return;
        }
        Db::tx(function () {
            Db::insert('users', [
                'id' => 'demo-user',
                'portal_sub' => 'demo',
                'username' => '林间旅人',
                'avatar' => null,
                'first_login' => time(),
                'platform_admin' => 1,
            ]);
            Db::insert('point_groups', ['id' => 'airport', 'name' => '临江国际机场']);
            foreach (
                [
                    ['riverside', '滨江公园', '沿江步道 · 南门', null, '101'],
                    ['museum', '城市美术馆', '艺术大道 · 东侧入口', null, '102'],
                    ['airport-departure', '国内出发', '临江国际机场 · T1 · 3 层', 'airport', '103'],
                    ['airport-arrival', '国内到达', '临江国际机场 · T1 · 1 层', 'airport', '104'],
                ]
                as [$id, $name, $desc, $group, $platform]
            ) {
                Db::insert('points', [
                    'id' => $id,
                    'name' => $name,
                    'description' => $desc,
                    'group_id' => $group,
                    'enabled' => 1,
                ]);
                Db::insert('point_platforms', [
                    'id' => Db::id(),
                    'point_id' => $id,
                    'platform_id' => $platform,
                ]);
            }
            foreach (
                [['jade', '青禾出行', 200, '201', '301'], ['river', '江岸交通', 260, '202', '302']]
                as [$id, $name, $rate, $depot, $siding]
            ) {
                $fare = Fare::defaults();
                $fare['perKmMinor'] = $rate;
                Db::insert('companies', [
                    'id' => $id,
                    'name' => $name,
                    'fare' => Db::json($fare),
                    'enabled' => 1,
                ]);
                Db::insert('memberships', [
                    'id' => Db::id(),
                    'company_id' => $id,
                    'user_id' => 'demo-user',
                    'role' => 'admin',
                ]);
                Db::insert('centers', [
                    'id' => $id . '-center',
                    'company_id' => $id,
                    'name' => $name . ' · 城区中心',
                    'enabled' => 1,
                    'depots' => Db::json([['depotId' => $depot, 'sidingId' => $siding]]),
                ]);
                $config = [
                    'maxSpeedKmh' => 60,
                    'accelerationMps2' => 1,
                    'decelerationMps2' => 1,
                    'vehicleCars' => [
                        [
                            'vehicleId' => 'minecart',
                            'capacity' => 4,
                            'length' => 4,
                            'width' => 2,
                            'bogie1Position' => -1,
                            'bogie2Position' => 1,
                            'couplingPadding1' => 0,
                            'couplingPadding2' => 0,
                        ],
                    ],
                ];
                Db::insert('vehicles', [
                    'id' => $id . '-comfort',
                    'company_id' => $id,
                    'name' => '舒适四座',
                    'capacity' => 4,
                    'config' => Db::json($config),
                ]);
                foreach (
                    ['riverside', 'museum', 'airport-departure', 'airport-arrival']
                    as $point
                ) {
                    Db::insert('company_points', [
                        'id' => Db::id(),
                        'company_id' => $id,
                        'point_id' => $point,
                    ]);
                }
            }
        });
    }
    public static function command(string $type, array $p): array
    {
        if ($type === 'taxi.quote') {
            return [
                'distanceMeters' => 4200,
                'estimatedSeconds' => 420,
                'estimatedLowSpeedSeconds' => 20,
                'pickupDistanceMeters' => $p['depotId'] === '201' ? 850 : 1400,
                'returnDistanceMeters' => 3600,
                'geometry' => self::geometry(0),
            ];
        }
        if (str_starts_with($type, 'mtr.')) {
            $kind = substr($type, 4);
            $items = [];
            if ($kind === 'platforms') {
                foreach (['101', '102', '103', '104'] as $i => $id) {
                    $items[] = [
                        'id' => $id,
                        'name' => ['公园南门', '美术馆', '国内出发', '国内到达'][$i],
                        'stationId' => $id,
                        'position' => ['x' => $i * 100, 'y' => 64, 'z' => $i * 80],
                    ];
                };
            }
            if ($kind === 'stations') {
                foreach (['101', '102', '103', '104'] as $id) {
                    $items[] = ['id' => $id, 'name' => '车站 ' . $id];
                };
            }
            if ($kind === 'depots') {
                foreach (['201', '202'] as $id) {
                    $items[] = [
                        'id' => $id,
                        'name' => '专用车厂 ' . $id,
                        'sidingCount' => 1,
                        'allowedForTaxi' => true,
                    ];
                };
            }
            if ($kind === 'sidings') {
                foreach (['301', '302'] as $i => $id) {
                    $items[] = [
                        'id' => $id,
                        'depotId' => (string) (201 + $i),
                        'name' => '停车轨道 ' . $id,
                    ];
                };
            }
            return ['items' => $items, 'nextOffset' => null, 'revision' => 'demo'];
        }
        if ($type === 'taxi.create') {
            $existing = Db::one('SELECT * FROM demo_rides WHERE id=?', [$p['orderId']]);
            if (!$existing) {
                foreach (Db::all('SELECT * FROM demo_rides WHERE destroyed=0') as $r) {
                    if (
                        Db::data($r['params'])['depotId'] === $p['depotId'] &&
                        time() - (int) $r['created_at'] < 60
                    ) {
                        throw new AppError('DEPOT_BUSY', '车辆正在执行其他订单', 409);
                    }
                }
                Db::insert('demo_rides', [
                    'id' => $p['orderId'],
                    'params' => Db::json($p),
                    'created_at' => time(),
                    'destroyed' => 0,
                ]);
            }
        }
        $r = Db::one('SELECT * FROM demo_rides WHERE id=?', [$p['orderId'] ?? '']);
        if (!$r) {
            throw new AppError('ORDER_NOT_FOUND', '订单尚未派车', 404);
        }
        $age = time() - (int) $r['created_at'];
        if ($type === 'taxi.depart') {
            if ($age < 8 || ($r['departed_at'] ?? null) !== null || $age >= 20) {
                throw new AppError('NOT_AT_PICKUP', '车辆尚未停靠或已经出发', 409);
            }
            Db::run('UPDATE demo_rides SET departed_at=? WHERE id=?', [time(), $r['id']]);
            $r['departed_at'] = time();
        }
        $start = $r['departed_at'] ?? (int) $r['created_at'] + 20;
        $tripAge = time() - (int) $start;
        if ($type === 'taxi.destroy') {
            if ($tripAge >= 0 && $tripAge < 20) {
                throw new AppError('VEHICLE_OCCUPIED', '行程中请联系调度员协助', 409);
            }
            Db::run('UPDATE demo_rides SET destroyed=1 WHERE id=?', [$r['id']]);
            $r['destroyed'] = 1;
        }
        $stage =
            $age < 8
                ? 'TO_PICKUP'
                : ($tripAge < 0
                    ? 'WAITING_PICKUP'
                    : ($tripAge < 20
                        ? 'ON_TRIP'
                        : ($tripAge < 24
                            ? 'WAITING_DROPOFF'
                            : 'RETURNING')));
        $progress =
            $tripAge < 0
                ? min(850, ($age / 8) * 850)
                : min(8650, 850 + (max(0, $tripAge) / 20) * 4200);
        if ($type === 'taxi.geometry') {
            return self::geometry($progress);
        }
        if ($type === 'taxi.logs') {
            return [
                'items' =>
                    $tripAge >= 0
                        ? [
                            [
                                'sequence' => 1,
                                'type' => 'ride.pickup_departure_released',
                                'timestamp' => (int) $start * 1000,
                            ],
                        ]
                        : [],
                'nextOffset' => null,
            ];
        }
        return [
            'orderId' => $r['id'],
            'phase' => $r['destroyed']
                ? 'DESTROYED'
                : ($age >= 60
                    ? 'COMPLETED'
                    : ($age < 2
                        ? 'GENERATING'
                        : 'ACTIVE')),
            'stage' => $stage,
            'onboardCount' =>
                $tripAge >= 0 && $tripAge < 20 ? (int) Db::data($r['params'])['passengerCount'] : 0,
            'stageLabel' => [
                'TO_PICKUP' => '前往上车地',
                'WAITING_PICKUP' => '等待上客',
                'ON_TRIP' => '行程中',
                'WAITING_DROPOFF' => '等待下客',
                'RETURNING' => '回库中',
            ][$stage],
            'position' => ['x' => 120 + $progress / 25, 'y' => 64, 'z' => 180 + $progress / 60],
            'speedKmh' => in_array($stage, ['WAITING_PICKUP', 'WAITING_DROPOFF'], true) ? 0 : 36,
            'etaPickupSeconds' => max(0, 8 - $age),
            'etaDropoffSeconds' => max(0, 20 - $tripAge),
            'geometry' => self::geometry($progress),
        ];
    }
    private static function geometry(float $progress): array
    {
        $points = [];
        for ($i = 0; $i <= 40; $i++) {
            $points[] = [
                $i * 216.25,
                120 + $i * 8,
                64,
                180 + sin($i / 5) * 55 + $i * 3,
                null,
                null,
            ];
        }
        return [
            'points' => $points,
            'progressMeters' => $progress,
            'totalMeters' => 8650,
            'legs' => [
                ['stage' => 'TO_PICKUP', 'startMeters' => 0, 'endMeters' => 850],
                ['stage' => 'ON_TRIP', 'startMeters' => 850, 'endMeters' => 5050],
                ['stage' => 'RETURNING', 'startMeters' => 5050, 'endMeters' => 8650],
            ],
        ];
    }
}
