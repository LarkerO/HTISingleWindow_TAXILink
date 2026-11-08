import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createHash, randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, dirname, basename } from 'node:path';
import { once } from 'node:events';

// Provider fixture follows Hydroline/hydcraft-portal v2 @ 497db20:
// service.ts, token/index.post.ts, userinfo/index.get.ts. It is not a live Portal login.
test('HydCraft Portal OAuth 端到端协议契约', { timeout: 60000 }, async (t) => {
  const directory = await mkdtemp(join(tmpdir(), 'taxilink-oauth-'));
  const redirects = [
    'https://taxi.test/api/auth/callback',
    'https://admin.taxi.test/api/auth/callback',
  ];
  const codes = new Map(),
    tokens = new Map();
  let profile = {
    sub: 'portal-user-no-minecraft',
    preferred_username: '旅人',
    name: '展示名',
    picture: 'https://cdn.test/avatar.png',
    hydroline_id: 'H123',
    role: 'OWNER',
    status: 'ACTIVE',
    locale: 'zh-CN',
  };
  let tokenCalls = 0,
    userinfoCalls = 0,
    badToken = false;
  const server = createServer(async (req, res) => {
    const send = (status, data) => {
      res.writeHead(status, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(data));
    };
    try {
      const url = new URL(req.url, 'http://localhost');
      if (url.pathname === '/oauth/authorize') {
        const q = Object.fromEntries(url.searchParams);
        assert.equal(q.client_id, 'hydcraft_contract');
        assert.equal(q.response_type, 'code');
        assert.equal(q.scope, 'profile hydroline');
        assert.equal(q.code_challenge_method, 'S256');
        assert.match(q.code_challenge, /^[A-Za-z0-9_-]{43}$/);
        assert.ok(redirects.includes(q.redirect_uri));
        const code = randomBytes(48).toString('base64url');
        codes.set(code, q);
        const callback = new URL(q.redirect_uri);
        callback.searchParams.set('code', code);
        callback.searchParams.set('state', q.state);
        res.writeHead(302, { Location: callback.href });
        res.end();
        return;
      }
      if (url.pathname === '/api/oauth/token') {
        tokenCalls++;
        assert.equal(req.method, 'POST');
        assert.equal(
          req.headers.authorization,
          'Basic ' + Buffer.from('hydcraft_contract:contract-secret').toString('base64'),
        );
        assert.match(req.headers['content-type'], /application\/json/);
        let raw = '';
        for await (const chunk of req) raw += chunk;
        const body = JSON.parse(raw),
          grant = codes.get(body.code);
        assert.equal(body.grant_type, 'authorization_code');
        assert.ok(grant, '授权码必须存在且仅使用一次');
        assert.equal(body.redirect_uri, grant.redirect_uri);
        assert.match(body.code_verifier, /^[A-Za-z0-9._~-]{43,128}$/);
        assert.equal(
          createHash('sha256').update(body.code_verifier).digest('base64url'),
          grant.code_challenge,
        );
        codes.delete(body.code);
        const access = randomBytes(48).toString('base64url');
        tokens.set(access, { ...profile });
        send(200, {
          access_token: badToken ? null : access,
          token_type: 'Bearer',
          expires_in: 900,
          scope: 'profile hydroline',
          refresh_token: 'opaque-refresh-token',
          refresh_token_expires_in: 2592000,
        });
        return;
      }
      if (url.pathname === '/api/oauth/userinfo') {
        userinfoCalls++;
        assert.equal(req.method, 'GET');
        const user = tokens.get(req.headers.authorization?.replace(/^Bearer /, ''));
        if (!user || user.status !== 'ACTIVE') {
          send(401, { code: 'OAUTH_ACCESS_TOKEN_INVALID' });
          return;
        }
        send(200, user);
        return;
      }
      send(404, {});
    } catch (e) {
      send(400, { contractError: e.message });
    }
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const env = {
    ...process.env,
    APP_ENV: 'test',
    DEMO_MODE: '0',
    REDIS_URL: '',
    APP_SECURE_COOKIE: '1',
    DB_DSN: 'sqlite:' + join(directory, 'oauth.sqlite'),
    PLATFORM_ADMIN_SUB: '',
    APP_ORIGIN: 'https://taxi.test',
    ADMIN_ORIGIN: 'https://admin.taxi.test',
    PORTAL_ISSUER: `http://127.0.0.1:${server.address().port}`,
    PORTAL_CLIENT_ID: 'hydcraft_contract',
    PORTAL_CLIENT_SECRET: 'contract-secret',
    PORTAL_REDIRECT_URI: redirects[0],
    PORTAL_ADMIN_REDIRECT_URI: redirects[1],
  };
  const clients = [];
  function driver() {
    const child = spawn(process.env.PHP_BINARY || 'php', ['backend/tests/oauth-driver.php'], {
      env,
      windowsHide: true,
    });
    let stderr = '';
    child.stderr.on('data', (d) => (stderr += d));
    const pending = [];
    createInterface({ input: child.stdout }).on('line', (line) => {
      const item = pending.shift();
      if (!item) return;
      try {
        const result = JSON.parse(line);
        if (result.driverError) throw new Error(result.driverError);
        item.resolve(result);
      } catch (e) {
        item.reject(e);
      }
    });
    child.on('error', (e) => pending.splice(0).forEach((p) => p.reject(e)));
    child.on('exit', (code) =>
      pending.splice(0).forEach((p) => p.reject(new Error(`PHP exited ${code}: ${stderr}`))),
    );
    const client = {
      child,
      call: (input) =>
        new Promise((resolve, reject) => {
          pending.push({ resolve, reject });
          child.stdin.write(JSON.stringify(input) + '\n');
        }),
    };
    clients.push(client);
    return client;
  }
  const api = driver();
  const header = (r, name) =>
    Object.entries(r.headers).find(([key]) => key.toLowerCase() === name.toLowerCase())?.[1];
  const cookies = (r) => [header(r, 'Set-Cookie')].flat().filter(Boolean);
  async function begin(admin = false, returnTo = '/profile?invite=example') {
    const host = admin ? 'admin.taxi.test' : 'taxi.test';
    const start = await api.call({
      path:
        '/api/auth/start?destination=' +
        (admin ? 'admin' : 'passenger') +
        '&returnTo=' +
        encodeURIComponent(returnTo),
      headers: { Host: host },
    });
    assert.equal(start.status, 302, start.body);
    assert.match(cookies(start)[0], /HttpOnly; SameSite=Lax; Max-Age=600; Secure/);
    assert.doesNotMatch(cookies(start)[0], /Domain=/i);
    const authorization = await fetch(header(start, 'Location'), { redirect: 'manual' });
    assert.equal(authorization.status, 302, await authorization.text());
    const callback = new URL(authorization.headers.get('location'));
    assert.equal(callback.hostname, host);
    return {
      path: callback.pathname + callback.search,
      headers: { Host: host, Cookie: cookies(start)[0].split(';')[0] },
    };
  }
  let firstUser, passengerCookie, adminCookie;
  try {
    await t.test('乘客端授权、PKCE、opaque token、userinfo 与独立 Cookie', async () => {
      const request = await begin();
      const result = await api.call(request);
      assert.equal(result.status, 302, result.body);
      assert.equal(result.setCookieCount, 2, '会话 Cookie 与 state 清理 Cookie 必须分别发出');
      assert.equal(header(result, 'Location'), 'https://taxi.test/profile?invite=example');
      passengerCookie = cookies(result)
        .find((c) => c.startsWith('tl_session='))
        .split(';')[0];
      assert.match(cookies(result).join(';'), /HttpOnly/);
      assert.equal(tokenCalls, 1);
      assert.equal(userinfoCalls, 1);
      const me = await api.call({ path: '/api/me', headers: { Cookie: passengerCookie } });
      firstUser = JSON.parse(me.body).user;
      assert.equal(firstUser.username, '旅人');
      assert.equal(firstUser.avatar, profile.picture);
      assert.equal(firstUser.platformAdmin, false);
      assert.equal(firstUser.memberships.length, 0);
      assert.ok(firstUser.firstLogin);
      assert.ok(firstUser.csrf);
      assert.doesNotMatch(me.body, /access_token|refresh_token|code_verifier|minecraft/i);
      const replay = await api.call(request);
      assert.equal(replay.status, 403);
      assert.equal(tokenCalls, 1);
    });
    await t.test(
      '管理端回调独立、同一 sub 复用账号并更新资料但不继承 Portal OWNER 权限',
      async () => {
        profile.preferred_username = '旅人改名';
        profile.picture = null;
        const result = await api.call(await begin(true, '/'));
        assert.equal(result.status, 302, result.body);
        assert.equal(header(result, 'Location'), 'https://admin.taxi.test/');
        adminCookie = cookies(result)
          .find((c) => c.startsWith('tl_session='))
          .split(';')[0];
        assert.notEqual(adminCookie, passengerCookie);
        const me = JSON.parse(
          (
            await api.call({
              path: '/api/me',
              headers: { Host: 'admin.taxi.test', Cookie: adminCookie },
            })
          ).body,
        ).user;
        assert.equal(me.id, firstUser.id);
        assert.equal(me.firstLogin, firstUser.firstLogin);
        assert.equal(me.username, '旅人改名');
        assert.equal(me.avatar, null);
        assert.equal(
          (await api.call({ path: '/api/admin/summary', headers: { Cookie: adminCookie } })).status,
          403,
        );
      },
    );
    await t.test('错误 state、过期 state、拒绝授权与缺失授权码不触发换码', async () => {
      let request = await begin();
      const before = tokenCalls;
      assert.equal(
        (await api.call({ ...request, headers: { Cookie: 'tl_oauth=wrong' } })).status,
        403,
      );
      assert.equal(tokenCalls, before);
      const denied = new URL(request.path, 'https://taxi.test');
      denied.searchParams.delete('code');
      denied.searchParams.set('error', 'access_denied');
      const response = await api.call({ ...request, path: denied.pathname + denied.search });
      assert.equal(JSON.parse(response.body).error.code, 'OAUTH_DENIED');
      assert.equal(tokenCalls, before);
      request = await begin();
      await api.call({ action: 'expire-state' });
      assert.equal((await api.call(request)).status, 403);
      request = await begin();
      const missing = new URL(request.path, 'https://taxi.test');
      missing.searchParams.delete('code');
      assert.equal(
        (await api.call({ ...request, path: missing.pathname + missing.search })).status,
        400,
      );
      assert.equal(tokenCalls, before);
    });
    await t.test('无效令牌与禁用账号不能建立本地会话', async () => {
      const before = await api.call({ action: 'stats' });
      badToken = true;
      assert.equal((await api.call(await begin())).status, 502);
      badToken = false;
      profile.status = 'SUSPENDED';
      assert.equal((await api.call(await begin())).status, 502);
      profile.status = 'ACTIVE';
      assert.equal((await api.call({ action: 'stats' })).sessions, before.sessions);
    });
    await t.test('多进程同时首次登录只创建一个 Portal 身份', async () => {
      const peers = Array.from({ length: 4 }, driver);
      await Promise.all(
        peers.map((p) =>
          p.call({
            action: 'login',
            profile: {
              sub: 'concurrent-portal-user',
              preferred_username: '并发账号',
              status: 'ACTIVE',
            },
          }),
        ),
      );
      const stats = await api.call({ action: 'stats' });
      assert.equal(stats.users.filter((u) => u.portal_sub === 'concurrent-portal-user').length, 1);
    });
    await t.test('退出当前应用会话立即失效，其他独立登录会话保留', async () => {
      const me = JSON.parse(
        (await api.call({ path: '/api/me', headers: { Cookie: passengerCookie } })).body,
      ).user;
      const logout = await api.call({
        method: 'POST',
        path: '/api/auth/logout',
        headers: { Cookie: passengerCookie, 'X-CSRF-Token': me.csrf, Origin: 'https://taxi.test' },
      });
      assert.equal(logout.status, 200);
      assert.equal(
        JSON.parse((await api.call({ path: '/api/me', headers: { Cookie: passengerCookie } })).body)
          .user,
        null,
      );
      assert.ok(
        JSON.parse((await api.call({ path: '/api/me', headers: { Cookie: adminCookie } })).body)
          .user,
      );
    });
  } finally {
    await Promise.all(
      clients.map(async ({ child }) => {
        const exited = once(child, 'exit');
        child.stdin.end();
        await exited;
      }),
    );
    server.close();
    await once(server, 'close');
    if (
      dirname(resolve(directory)) !== resolve(tmpdir()) ||
      !basename(directory).startsWith('taxilink-oauth-')
    )
      throw new Error('Unexpected test cleanup directory');
    await rm(directory, { recursive: true, force: true });
  }
});
