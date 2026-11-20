import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

test('并行报价、短缓存、超时预算与异常隔离', { timeout: 15000 }, async () => {
  let bookingFailure = false;
  let active = 0,
    peak = 0,
    calls = 0;
  const server = createServer(async (req, res) => {
    let raw = '';
    for await (const chunk of req) raw += chunk;
    const input = JSON.parse(raw);
    assert.equal(req.headers.authorization, 'Bearer ' + 'a'.repeat(40));
    assert.equal(input.type, 'taxi.quote');
    active++;
    calls++;
    peak = Math.max(peak, active);
    const p = input.params;
    setTimeout(
      () => {
        active--;
        res.setHeader('Content-Type', 'application/json');
        if (bookingFailure && p.depotId === '202') {
          res.statusCode = 503;
          return res.end('{}');
        }
        if (p.fixture === 'empty') return res.end();
        if (p.fixture === 'bad')
          return res.end(
            JSON.stringify({ ok: false, error: { code: 'NO_ROUTE', message: '无连通路线' } }),
          );
        if (p.fixture === 'invalid')
          return res.end(JSON.stringify({ ok: true, data: { distanceMeters: -1 } }));
        res.end(
          JSON.stringify({
            ok: true,
            data: {
              distanceMeters: 2000,
              estimatedSeconds: 200,
              pickupDistanceMeters: 100,
              returnDistanceMeters: 600,
            },
          }),
        );
      },
      p.fixture === 'slow' ? 600 : 80,
    );
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const directory = await mkdtemp(join(tmpdir(), 'taxilink-quotes-'));
  const child = spawn(process.env.PHP_BINARY || 'php', ['backend/tests/quote-driver.php'], {
    windowsHide: true,
    env: {
      ...process.env,
      APP_ENV: 'test',
      DEMO_MODE: '0',
      REDIS_URL: '',
      DB_DSN: 'sqlite:' + join(directory, 'quotes.sqlite'),
      GATEWAY_URL: `http://127.0.0.1:${server.address().port}`,
      GATEWAY_TOKEN: 'a'.repeat(40),
    },
  });
  const pending = [];
  let stderr = '';
  child.stderr.on('data', (d) => (stderr += d));
  createInterface({ input: child.stdout }).on('line', (line) => {
    const p = pending.shift();
    try {
      const r = JSON.parse(line);
      if (r.driverError) throw new Error(r.driverError);
      p.resolve(r);
    } catch (e) {
      p.reject(e);
    }
  });
  child.on('error', (e) => pending.splice(0).forEach((p) => p.reject(e)));
  child.on('exit', (code) =>
    pending.splice(0).forEach((p) => p.reject(new Error(`PHP exited ${code}: ${stderr}`))),
  );
  const call = (input) =>
    new Promise((resolve, reject) => {
      pending.push({ resolve, reject });
      child.stdin.write(JSON.stringify(input) + '\n');
    });
  try {
    const params = Array.from({ length: 12 }, (_, id) => ({ id }));
    const first = await call({ params });
    assert.equal(Object.values(first.routes).filter((v) => !v.error).length, 12);
    assert.equal(peak, 4, '只允许四个在途预览');
    assert.ok(first.elapsed < 0.8, `12 个 80ms 请求应并行完成，实际 ${first.elapsed}s`);
    const before = calls;
    const cached = await call({ params });
    assert.equal(calls, before, '跨请求缓存不重复访问模组');
    assert.ok(cached.elapsed < 0.1);
    const mixed = await call({
      params: [{ fixture: 'bad' }, { fixture: 'empty' }, { fixture: 'invalid' }, { id: 'healthy' }],
    });
    assert.deepEqual(
      Object.values(mixed.routes)
        .map((v) => v.error ?? 'ok')
        .sort(),
      ['UPSTREAM_INVALID_RESPONSE', 'NO_ROUTE', 'UPSTREAM_INVALID_RESPONSE', 'ok'].sort(),
    );
    const slow = await call({
      params: Array.from({ length: 12 }, (_, id) => ({ id, fixture: 'slow' })),
      budget: 0.2,
    });
    assert.ok(slow.elapsed < 0.6, `整体预算应限制等待，实际 ${slow.elapsed}s`);
    assert.equal(Object.values(slow.routes).filter((v) => v.error).length, 12);
    const booking = await call({ action: 'booking' });
    assert.equal(booking.offers.length, 2);
    assert.ok(booking.complete);
    assert.ok(
      booking.offers.every((v) => !('params' in v)),
      '乘客报价不泄漏派车参数',
    );
    await call({ action: 'purge' });
    bookingFailure = true;
    const partial = await call({ action: 'booking' });
    assert.equal(partial.offers.length, 1, '单个企业网关失败时保留其他成功报价');
    assert.equal(partial.complete, false);
    assert.ok(partial.warning.includes('尚未完成'));
  } finally {
    const exited = once(child, 'exit');
    child.stdin.end();
    await exited;
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
    await rm(directory, { recursive: true, force: true });
  }
});
