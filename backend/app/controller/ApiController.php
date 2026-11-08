<?php
declare(strict_types=1);
namespace app\controller;
use app\domain\{Admin, AppError, Auth, Booking, Bridge, Db, Dispatch, Env, Http, RateLimit};
use support\{Request, Response};

final class ApiController
{
    public function handle(Request $r): Response
    {
        try {
            $response = $this->route($r);
            return ($response instanceof Response ? $response : json($response))->withHeaders([
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (AppError $e) {
            return json(['error' => ['code' => $e->key, 'message' => $e->getMessage()]])
                ->withStatus($e->status)
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            \support\Log::error($e->__toString());
            return json([
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => '服务暂不可用，请稍后重试'],
            ])->withStatus(500);
        }
    }
    private function route(Request $r): mixed
    {
        $path = $r->path();
        $method = $r->method();
        if ($method === 'GET' && $path === '/api/health') {
            Db::one('SELECT 1');
            return ['status' => 'ok', 'demo' => Env::demo(), 'payment' => 'demonstration'];
        }
        if ($method === 'GET' && $path === '/api/points') {
            $q = trim((string) $r->get('q', ''));
            $items = Db::all(
                'SELECT * FROM points WHERE enabled=1 AND (name LIKE ? OR description LIKE ?) ORDER BY name',
                ['%' . $q . '%', '%' . $q . '%'],
            );
            return [
                'groups' => Db::all('SELECT * FROM point_groups ORDER BY name'),
                'items' => $items,
            ];
        }
        if ($method === 'GET' && $path === '/api/auth/start') {
            RateLimit::check('login:' . $r->getRealIp(), 20);
            if (!Env::get('PORTAL_CLIENT_ID') || !Env::get('PORTAL_CLIENT_SECRET')) {
                throw new AppError('OAUTH_NOT_CONFIGURED', 'Portal 登录尚未配置', 503);
            }
            $state = Db::id();
            $destination = $r->get('destination') === 'admin' ? 'admin' : 'passenger';
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            Db::insert('oauth_states', [
                'id' => hash('sha256', $state),
                'verifier' => $verifier,
                'destination' => $destination,
                'return_to' => Auth::returnPath((string) $r->get('returnTo', '/')),
                'expires' => time() + 600,
            ]);
            $query = http_build_query([
                'response_type' => 'code',
                'client_id' => Env::get('PORTAL_CLIENT_ID'),
                'redirect_uri' => Auth::oauthRedirect($destination),
                'scope' => 'profile hydroline',
                'state' => $state,
                'code_challenge' => rtrim(
                    strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'),
                    '=',
                ),
                'code_challenge_method' => 'S256',
            ]);
            return redirect(
                rtrim(Env::get('PORTAL_ISSUER'), '/') . '/oauth/authorize?' . $query,
            )->withHeader('Set-Cookie', Auth::cookie('tl_oauth', $state, 600));
        }
        if ($method === 'GET' && $path === '/api/auth/callback') {
            $state = (string) $r->get('state', '');
            if (!$state || !hash_equals((string) $r->cookie('tl_oauth', ''), $state)) {
                throw new AppError('OAUTH_STATE_INVALID', '登录验证已失效，请重新登录', 403);
            }
            $entry = Db::tx(function () use ($state) {
                $x = Db::one('SELECT * FROM oauth_states WHERE id=?' . Db::lock(), [
                    hash('sha256', $state),
                ]);
                if (!$x || $x['expires'] < time()) {
                    throw new AppError('OAUTH_STATE_EXPIRED', '登录请求已过期', 403);
                }
                Db::run('DELETE FROM oauth_states WHERE id=?', [$x['id']]);
                return $x;
            });
            if ($r->get('error')) {
                throw new AppError('OAUTH_DENIED', 'Portal 授权未完成，请返回应用重新登录', 403);
            }
            $code = $r->get('code');
            if (!is_string($code) || $code === '') {
                throw new AppError('OAUTH_CODE_MISSING', 'Portal 回调缺少授权码，请重新登录', 400);
            }
            $issuer = rtrim(Env::get('PORTAL_ISSUER'), '/');
            $token = Http::request(
                $issuer . '/api/oauth/token',
                [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => Auth::oauthRedirect($entry['destination']),
                    'code_verifier' => $entry['verifier'],
                ],
                [
                    'Authorization: Basic ' .
                    base64_encode(
                        Env::get('PORTAL_CLIENT_ID') . ':' . Env::get('PORTAL_CLIENT_SECRET'),
                    ),
                ],
            );
            if (
                !is_string($token['access_token'] ?? null) ||
                $token['access_token'] === '' ||
                strcasecmp((string) ($token['token_type'] ?? ''), 'Bearer') !== 0
            ) {
                throw new AppError('OAUTH_TOKEN_INVALID', 'Portal 返回的令牌格式无效', 502);
            }
            $profile = Http::request($issuer . '/api/oauth/userinfo', null, [
                'Authorization: Bearer ' . $token['access_token'],
            ]);
            $session = Auth::login($profile);
            return redirect(
                rtrim(
                    Env::get($entry['destination'] === 'admin' ? 'ADMIN_ORIGIN' : 'APP_ORIGIN'),
                    '/',
                ) . Auth::returnPath($entry['return_to'] ?? '/'),
            )->withHeaders([
                'Set-Cookie' => [
                    Auth::cookie('tl_session', $session, 7200),
                    Auth::cookie('tl_oauth', '', 0),
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/api/auth/demo') {
            if (!Env::demo()) {
                throw new AppError('NOT_FOUND', '接口不存在', 404);
            }
            $origin = $r->header('origin');
            if (
                $origin &&
                !in_array($origin, [Env::get('APP_ORIGIN'), Env::get('ADMIN_ORIGIN')], true)
            ) {
                throw new AppError('ORIGIN_INVALID', '请求来源无效', 403);
            }
            RateLimit::check('demo:' . $r->getRealIp(), 10);
            return json(['ok' => true])->withHeader(
                'Set-Cookie',
                Auth::cookie(
                    'tl_session',
                    Auth::login(['sub' => 'demo', 'preferred_username' => '林间旅人']),
                    7200,
                ),
            );
        }
        if ($method === 'GET' && $path === '/api/me') {
            $u = Auth::session($r, false);
            return ['user' => $u ? Auth::publicUser($u) : null, 'demo' => Env::demo()];
        }
        $u = Auth::session($r);
        if ($method !== 'GET') {
            Auth::csrf($r, $u);
        }
        $input = $method === 'GET' ? [] : $r->post();
        if (!is_array($input)) {
            throw new AppError('INVALID_BODY', '请求内容无效');
        }
        if ($method === 'POST' && $path === '/api/auth/logout') {
            Db::run('DELETE FROM sessions WHERE id=?', [$u['session_id']]);
            return json(['ok' => true])->withHeader(
                'Set-Cookie',
                Auth::cookie('tl_session', '', 0),
            );
        }
        if ($method === 'POST' && $path === '/api/quotes') {
            RateLimit::check('quote:' . $u['id'], 12);
            return Booking::quote($u, $input);
        }
        if ($path === '/api/orders') {
            if ($method === 'POST') {
                RateLimit::check('order:' . $u['id'], 10);
                return Booking::create($u, $input, (string) $r->header('idempotency-key', ''));
            }
            if ($method === 'GET') {
                $page = max(1, (int) $r->get('page', 1));
                $offset = ($page - 1) * 30;
                return [
                    'items' => array_map(
                        Booking::view(...),
                        Db::all(
                            "SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 30 OFFSET $offset",
                            [$u['id']],
                        ),
                    ),
                    'total' => (int) Db::one('SELECT COUNT(*) AS n FROM orders WHERE user_id=?', [
                        $u['id'],
                    ])['n'],
                    'page' => $page,
                ];
            }
        }
        if (
            preg_match('#^/api/orders/([a-z0-9-]+)(?:/(pay|depart|cancel|events))?$#D', $path, $m)
        ) {
            $id = $m[1];
            $action = $m[2] ?? '';
            $o = Auth::order($u, $id);
            if ($method === 'GET') {
                if ($action === 'events') {
                    return [
                        'items' => Db::all(
                            'SELECT event_type,data,created_at FROM events WHERE order_id=? ORDER BY created_at,id',
                            [$id],
                        ),
                        'payments' => Db::all(
                            'SELECT * FROM payments WHERE order_id=? ORDER BY created_at,id',
                            [$id],
                        ),
                    ];
                }
                return Booking::view($o);
            }
            if ($method === 'POST' && $action === 'pay') {
                return Booking::pay($u, $id);
            }
            if ($method === 'POST' && $action === 'depart') {
                if (
                    $o['state'] !== 'ACTIVE' ||
                    (Db::data($o['telemetry'])['stage'] ?? '') !== 'WAITING_PICKUP'
                ) {
                    throw new AppError('NOT_AT_PICKUP', '车辆尚未停靠或已经出发', 409);
                }
                Bridge::command('taxi.depart', ['orderId' => $id]);
                Booking::event($id, 'passenger.depart_requested');
                return ['ok' => true];
            }
            if ($method === 'POST' && $action === 'cancel') {
                Db::tx(function () use ($id) {
                    $o = Db::one('SELECT * FROM orders WHERE id=?' . Db::lock(), [$id]);
                    if (in_array($o['state'], ['AWAITING_PAYMENT', 'QUEUED'], true)) {
                        Dispatch::finish($o, 'CANCELLED', 'passenger.cancelled', 0);
                    } elseif (!in_array($o['state'], ['COMPLETED', 'CANCELLED', 'FAILED'], true)) {
                        Db::run(
                            "UPDATE orders SET revision=revision+1,state='CANCEL_REQUESTED',updated_at=? WHERE id=?",
                            [time(), $id],
                        );
                        Booking::event($id, 'passenger.cancel_requested');
                    }
                });
                return Booking::view(Db::one('SELECT * FROM orders WHERE id=?', [$id]));
            }
        }
        if ($method === 'POST' && $path === '/api/invitations/accept') {
            return Admin::accept($u, (string) ($input['token'] ?? ''));
        }
        if ($method === 'GET' && $path === '/api/admin/summary') {
            $company = $r->get('companyId');
            $where = '';
            $args = [];
            if ($company) {
                Auth::company($u, $company);
                $where =
                    ' WHERE company_id=? OR (company_id IS NULL AND EXISTS (SELECT 1 FROM candidates c WHERE c.order_id=orders.id AND c.company_id=?))';
                $args = [$company, $company];
            } else {
                Auth::platform($u);
            }
            return Db::one(
                "SELECT COUNT(*) AS total,SUM(CASE WHEN state IN ('ACTIVE','DISPATCHING','FINALIZING') THEN 1 ELSE 0 END) AS active,SUM(CASE WHEN state='QUEUED' THEN 1 ELSE 0 END) AS queued,SUM(CASE WHEN state='NEEDS_ATTENTION' THEN 1 ELSE 0 END) AS attention FROM orders" .
                    $where,
                $args,
            );
        }
        if (
            $method === 'POST' &&
            preg_match('#^/api/admin/companies/([a-z0-9-]+)/fare$#', $path, $m)
        ) {
            Auth::company($u, $m[1], true);
            $fare = \app\domain\Fare::validate($input['fare'] ?? []);
            Db::run('UPDATE companies SET fare=? WHERE id=?', [Db::json($fare), $m[1]]);
            Admin::audit($u, 'fare.updated', $m[1], $fare);
            return ['ok' => true];
        }
        if (preg_match('#^/api/admin/orders/([a-z0-9-]+)/(events|control)$#', $path, $m)) {
            $o = Db::one('SELECT * FROM orders WHERE id=?', [$m[1]]);
            if (!$o) {
                throw new AppError('NOT_FOUND', '订单不存在', 404);
            }
            if ($o['company_id']) {
                Auth::company($u, $o['company_id']);
            } elseif (
                !$u['platform_admin'] &&
                !Db::one(
                    'SELECT c.id FROM candidates c JOIN memberships m ON m.company_id=c.company_id WHERE c.order_id=? AND m.user_id=?',
                    [$o['id'], $u['id']],
                )
            ) {
                throw new AppError('FORBIDDEN', '无权访问该订单', 403);
            }
            if ($m[2] === 'events' && $method === 'GET') {
                return [
                    'items' => Db::all(
                        'SELECT event_type,data,created_at FROM events WHERE order_id=? ORDER BY created_at,id',
                        [$o['id']],
                    ),
                ];
            }
            if ($m[2] === 'control' && $method === 'POST') {
                if (!$o['company_id']) {
                    Auth::platform($u);
                }
                $action = $input['action'] ?? '';
                if (!in_array($action, ['emergency', 'resume', 'cancel'], true)) {
                    throw new AppError('INVALID_ACTION', '调度操作无效');
                }
                if ($action === 'cancel') {
                    Db::tx(function () use ($o): void {
                        $current = Db::one('SELECT * FROM orders WHERE id=?' . Db::lock(), [
                            $o['id'],
                        ]);
                        if (
                            in_array($current['state'], ['COMPLETED', 'CANCELLED', 'FAILED'], true)
                        ) {
                            return;
                        }
                        if ((Db::data($current['telemetry'])['phase'] ?? '') === 'RECOVERED') {
                            Dispatch::finish($current, 'FAILED', 'admin.closed_recovered', 0);
                        } elseif (
                            in_array($current['state'], ['AWAITING_PAYMENT', 'QUEUED'], true)
                        ) {
                            Dispatch::finish($current, 'CANCELLED', 'admin.cancelled', 0);
                        } else {
                            Db::run(
                                "UPDATE orders SET revision=revision+1,state='CANCEL_REQUESTED',updated_at=? WHERE id=?",
                                [time(), $o['id']],
                            );
                        }
                    });
                } else {
                    Bridge::command('taxi.control', ['orderId' => $o['id'], 'mode' => $action]);
                }
                Admin::audit($u, 'order.' . $action, $o['id'], []);
                return ['ok' => true];
            }
        }
        if (
            $method === 'GET' &&
            preg_match('#^/api/admin/catalog/(depots|sidings|stations|platforms)$#', $path, $m)
        ) {
            if (
                !$u['platform_admin'] &&
                !Db::one('SELECT id FROM memberships WHERE user_id=?', [$u['id']])
            ) {
                throw new AppError('FORBIDDEN', '需要管理权限', 403);
            }
            return Bridge::catalog($m[1]);
        }
        if (preg_match('#^/api/admin/companies/([a-z0-9-]+)/(scope|invitations)$#', $path, $m)) {
            Auth::company($u, $m[1]);
            if ($m[2] === 'scope' && $method === 'GET') {
                return [
                    'pointIds' => array_column(
                        Db::all('SELECT point_id FROM company_points WHERE company_id=?', [$m[1]]),
                        'point_id',
                    ),
                ];
            }
            if ($method === 'POST') {
                return $m[2] === 'scope'
                    ? Admin::scope($u, $m[1], $input)
                    : Admin::invite($u, $m[1], (string) ($input['role'] ?? 'admin'));
            }
        }
        if (
            $method === 'GET' &&
            preg_match('#^/api/admin/points/([a-z0-9-]+)/platforms$#', $path, $m)
        ) {
            Auth::platform($u);
            return array_column(
                Db::all('SELECT platform_id FROM point_platforms WHERE point_id=?', [$m[1]]),
                'platform_id',
            );
        }
        if (
            preg_match(
                '#^/api/admin/(companies|centers|vehicles|point_groups|points|memberships|orders|payments|audit_logs)(?:/([a-z0-9-]+))?$#',
                $path,
                $m,
            )
        ) {
            if ($method === 'GET') {
                return Admin::list($u, $m[1], $r->get('companyId'));
            }
            if ($method === 'POST' || $method === 'PATCH') {
                return Admin::save($u, $m[1], $input, $m[2] ?? null);
            }
            if ($method === 'DELETE' && $m[1] === 'memberships' && isset($m[2])) {
                Auth::platform($u);
                Db::run('DELETE FROM memberships WHERE id=?', [$m[2]]);
                Admin::audit($u, 'membership.removed', $m[2], []);
                return ['ok' => true];
            }
        }
        throw new AppError('NOT_FOUND', '接口不存在', 404);
    }
}
