import { test } from 'node:test';
import assert from 'node:assert/strict';
const base = process.env.API_URL || 'http://127.0.0.1:8787';
test('公开与私有接口隔离、CSRF、报价及请求幂等', async () => {
  const anonymous = await fetch(base + '/api/orders');
  assert.equal(anonymous.status, 401);
  const login = await fetch(base + '/api/auth/demo', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: '{}',
  });
  assert.equal(login.status, 200);
  const cookie = login.headers.get('set-cookie').split(';')[0];
  const me = await (await fetch(base + '/api/me', { headers: { Cookie: cookie } })).json();
  assert.ok(me.user.csrf);
  const headers = {
    Cookie: cookie,
    'Content-Type': 'application/json',
    'X-CSRF-Token': me.user.csrf,
  };
  const bad = await fetch(base + '/api/quotes', {
    method: 'POST',
    headers: { Cookie: cookie, 'Content-Type': 'application/json' },
    body: '{}',
  });
  assert.equal(bad.status, 403);
  const q = await (
    await fetch(base + '/api/quotes', {
      method: 'POST',
      headers,
      body: JSON.stringify({ pickupId: 'riverside', dropoffId: 'museum', passengers: 1 }),
    })
  ).json();
  assert.ok(q.offers.length >= 2, JSON.stringify(q));
  const body = JSON.stringify({ quoteId: q.id, companyIds: ['jade', 'river'] });
  headers['Idempotency-Key'] = 'api-test-' + crypto.randomUUID();
  const first = await (await fetch(base + '/api/orders', { method: 'POST', headers, body })).json();
  const second = await (
    await fetch(base + '/api/orders', { method: 'POST', headers, body })
  ).json();
  assert.equal(first.id, second.id);
  const cancel = await fetch(base + '/api/orders/' + first.id + '/cancel', {
    method: 'POST',
    headers,
    body: '{}',
  });
  assert.equal(cancel.status, 200);
  const origin = await fetch(base + '/api/auth/demo', {
    method: 'POST',
    headers: { Origin: 'https://attacker.example', 'Content-Type': 'application/json' },
    body: '{}',
  });
  assert.equal(origin.status, 403);
});
