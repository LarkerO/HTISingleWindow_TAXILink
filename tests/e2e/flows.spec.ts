import { test, expect } from '@playwright/test';
test('游客主动登录提示、地址选择、报价与下单', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('/');
  await expect(page.getByRole('heading', { name: '下一站， 想去哪里？' })).toBeVisible();
  await page.screenshot({ path: 'artifacts/passenger-home.png', fullPage: true });
  await page.getByRole('button', { name: /行程$/ }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.getByRole('button', { name: '稍后再说' }).click();
  await page.getByRole('button', { name: '从哪里出发 选择上车点' }).click();
  await page.getByRole('button', { name: /滨江公园/ }).click();
  await page.getByRole('button', { name: '想去的地方 选择下车点' }).click();
  await page.getByRole('button', { name: /城市美术馆/ }).click();
  await page.getByRole('button', { name: /确认打车/ }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.getByRole('button', { name: '体验演示账号' }).click();
  await expect(page.getByRole('heading', { name: '选择你的出行' })).toBeVisible();
  await expect(page.getByRole('button', { name: /确认打车 · 已选 2 家企业/ })).toBeEnabled();
  await page.screenshot({ path: 'artifacts/passenger-offers.png', fullPage: true });
  await page.getByRole('button', { name: /确认打车 · 已选 2 家企业/ }).click();
  await expect(page).toHaveURL(/\/trip\//);
  await expect(page.getByRole('img', { name: '行驶轨迹地图，可拖动及双指缩放' })).toBeVisible();
  await expect(page.getByText('实时同步', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: '放大地图' }).click();
  await page.getByRole('button', { name: '回原点' }).click();
  await expect(page.getByRole('button', { name: '我已上车，确认发车' })).toBeVisible({
    timeout: 20000,
  });
  await page.getByRole('button', { name: '我已上车，确认发车' }).click();
  await expect(page.getByText('行程中', { exact: true })).toBeVisible({ timeout: 10000 });
  await page.screenshot({ path: 'artifacts/passenger-trip.png', fullPage: true });
  expect(errors).toEqual([]);
});
test('管理端主动登录、企业切换、费率及运营范围', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('http://localhost:5174');
  await page.getByRole('button', { name: /登录运营管理平台/ }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.getByRole('button', { name: '体验演示管理员' }).click();
  await expect(page.getByRole('heading', { name: '每一程，都井然有序。' })).toBeVisible();
  await page.screenshot({ path: 'artifacts/admin-overview.png', fullPage: true });
  await page.getByRole('link', { name: '运营范围' }).click();
  await expect(page.getByText('运营范围白名单')).toBeVisible();
  await expect(page.getByText('当前可运营地点 · 4')).toBeVisible();
  await page.getByRole('link', { name: '计费规则' }).click();
  await expect(page.getByText('企业统一计费规则')).toBeVisible();
  await page.getByRole('button', { name: '保存计费规则' }).click();
  await expect(page.getByText('计费规则已保存，新报价开始生效')).toBeVisible();
  await page.getByRole('link', { name: '成员与身份' }).click();
  await expect(page.getByRole('button', { name: '邀请管理员' })).toBeVisible();
  await page.getByRole('button', { name: '邀请调度员' }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  expect(errors).toEqual([]);
});
test('演示收银台可预付并返回候补行程', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.request.post('http://localhost:5173/api/auth/demo', { data: {} });
  const me = await (await page.request.get('http://localhost:5173/api/me')).json();
  const headers = { 'X-CSRF-Token': me.user.csrf };
  const companies = await (
    await page.request.get('http://localhost:5173/api/admin/companies')
  ).json();
  const original = JSON.parse(companies.find((c: any) => c.id === 'jade').fare);
  await page.request.post('http://localhost:5173/api/admin/companies/jade/fare', {
    headers,
    data: { fare: { ...original, payment: 'before' } },
  });
  const q = await (
    await page.request.post('http://localhost:5173/api/quotes', {
      headers,
      data: { pickupId: 'riverside', dropoffId: 'museum', passengers: 1 },
    })
  ).json();
  await page.request.post('http://localhost:5173/api/admin/companies/jade/fare', {
    headers,
    data: { fare: original },
  });
  const o = await (
    await page.request.post('http://localhost:5173/api/orders', {
      headers: { ...headers, 'Idempotency-Key': crypto.randomUUID() },
      data: { quoteId: q.id, companyIds: ['jade'] },
    })
  ).json();
  expect(o.state).toBe('AWAITING_PAYMENT');
  await page.goto('/checkout/' + o.id);
  await expect(page.getByText('演示支付 · 不会扣除任何游戏内货币')).toBeVisible();
  await page.getByRole('button', { name: '确认演示支付' }).click();
  await expect(page.getByText('演示支付完成')).toBeVisible();
  await page.screenshot({ path: 'artifacts/passenger-checkout.png', fullPage: true });
  await page.getByRole('button', { name: '返回行程' }).click();
  await expect(page).toHaveURL(/\/trip\//);
});
