import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { resolve, extname } from 'node:path';
import { chromium, expect } from '@playwright/test';

const artifacts = resolve('artifacts/review-20261120');
const user = {
  id: 'admin',
  username: '内测管理员',
  csrf: 'test-csrf',
  platformAdmin: true,
  memberships: [],
};
async function serve(root) {
  root = resolve(root);
  const server = createServer(async (req, res) => {
    const path = resolve(root, '.' + new URL(req.url, 'http://localhost').pathname);
    if (
      path !== root &&
      !path.startsWith(root + '/'.replace('/', process.platform === 'win32' ? '\\' : '/'))
    ) {
      res.writeHead(403).end();
      return;
    }
    let file = path,
      data;
    try {
      data = await readFile(file);
    } catch {
      file = root + '/index.html';
      data = await readFile(file);
    }
    res.setHeader(
      'Content-Type',
      {
        '.html': 'text/html',
        '.js': 'text/javascript',
        '.css': 'text/css',
        '.png': 'image/png',
        '.woff2': 'font/woff2',
      }[extname(file)] ?? 'application/octet-stream',
    );
    res.end(data);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  return {
    base: `http://127.0.0.1:${server.address().port}`,
    close: () => new Promise((resolve) => server.close(resolve)),
  };
}
async function launch() {
  return chromium.launch({
    executablePath:
      process.env.TAXI_SMOKE_BROWSER ||
      'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    headless: true,
  });
}

test('管理端可保存 COS 和并行乘客端，凭据保存后清空', { timeout: 30000 }, async () => {
  const server = await serve('admin/dist');
  const browser = await launch();
  const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
  let savedCos, savedClients;
  try {
    await page.route('**/api/**', async (route) => {
      const req = route.request(),
        path = new URL(req.url()).pathname;
      let data = [];
      if (path === '/api/me') data = { user, demo: false };
      if (path === '/api/admin/summary') data = {};
      if (path === '/api/admin/media/settings') {
        if (req.method() === 'POST') {
          savedCos = req.postDataJSON();
          assert.equal(req.headers()['x-csrf-token'], user.csrf);
        }
        data = {
          configured: !!savedCos,
          bucket: savedCos?.bucket ?? '',
          region: savedCos?.region ?? '',
          publicUrl: '',
          secretIdConfigured: !!savedCos,
          secretKeyConfigured: !!savedCos,
        };
      }
      if (path === '/api/admin/passenger-clients') {
        if (req.method() === 'POST') savedClients = req.postDataJSON();
        data = {
          origins: savedClients?.origins ?? ['https://taxi.test'],
          primary: 'https://taxi.test',
        };
      }
      await route.fulfill({ json: data });
    });
    await page.goto(server.base + '/#/media');
    await page.getByLabel('存储桶（含 APPID 后缀）').fill('images-1234567890');
    await page.getByLabel('地域', { exact: true }).fill('ap-guangzhou');
    await page.getByLabel('SecretId', { exact: true }).fill('AKIDTEST123456789');
    await page.getByLabel('SecretKey', { exact: true }).fill('SecretKeyTest123456789');
    await page.getByRole('button', { name: /^保\s*存$/ }).click();
    await expect.poll(() => savedCos?.bucket).toBe('images-1234567890');
    await expect(page.getByLabel('SecretKey', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('SecretId', { exact: true })).toHaveValue('');
    await mkdir(artifacts, { recursive: true });
    await page.screenshot({ path: artifacts + '/cos-settings.png', fullPage: true });
    await page.getByText('乘客端接入', { exact: true }).first().click();
    await page.getByLabel('允许的乘客端地址').fill('https://taxi.test\nhttps://v2.taxi.test');
    await page.getByRole('button', { name: /^保\s*存$/ }).click();
    await expect
      .poll(() => savedClients?.origins)
      .toEqual(['https://taxi.test', 'https://v2.taxi.test']);
    await page.screenshot({ path: artifacts + '/passenger-clients.png', fullPage: true });
  } finally {
    await browser.close();
    await server.close();
  }
});

async function mapFixture(page) {
  let mode = 'failed';
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    return route.fulfill({
      json:
        path === '/api/me'
          ? { user: null, demo: false }
          : path === '/api/map-settings'
            ? { mapId: 'fixture', allow3d: true }
            : path === '/api/points'
              ? { items: [], groups: [] }
              : { items: [], total: 0 },
    });
  });
  await page.route('**/bluemap/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/settings.json'))
      return route.fulfill({
        json: { startPos: [0, 0], lowres: { tileSize: [500, 500], lodFactor: 5, lodCount: 3 } },
      });
    if (path.endsWith('.png')) return route.fulfill({ status: 404 });
    return route.fulfill({
      contentType: 'text/html',
      body:
        mode === 'failed'
          ? '<body><div id="bm-app-err">Failed to load BlueMap webapp!</div></body>'
          : `<body><canvas width="390"></canvas><script>window.bluemap={mapViewer:{map:{},data:{superSampling:2,loadedHiresViewDistance:200},renderer:{domElement:document.querySelector('canvas'),getContext:()=>({isContextLost:()=>window.lost===true}),info:{render:{calls:1}}},updateLoadedMapArea(){}}};</script></body>`,
    });
  });
  return { healthy: () => (mode = 'ready') };
}

test('Vue v1 地图初始化失败及 WebGL 丢失后恢复 2D', { timeout: 25000 }, async () => {
  const server = await serve('passenger/dist'),
    browser = await launch();
  const page = await browser.newPage({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  try {
    const fixture = await mapFixture(page);
    await page.goto(server.base);
    await page.getByRole('button', { name: '切换到 3D' }).click();
    await expect(page.getByRole('status')).toContainText('已恢复 2D');
    await expect(page.locator('iframe')).toHaveCount(0);
    fixture.healthy();
    await page.getByRole('button', { name: '切换到 3D' }).click();
    await expect
      .poll(() => page.locator('iframe').contentFrame().locator('canvas').count())
      .toBe(1);
    const frame = page.frames().find((frame) => frame.url().includes('/bluemap/'));
    await frame.waitForFunction(() => window.__taxilinkMapHealth);
    await expect(page.locator('iframe')).toHaveCount(1);
    await frame.evaluate(() => (window.lost = true));
    await expect(page.getByRole('status')).toContainText('已恢复 2D');
    await page.screenshot({ path: artifacts + '/vue-map-recovery.png', fullPage: true });
  } finally {
    await browser.close();
    await server.close();
  }
});

test(
  'v2 H5 3D 初始化失败后恢复 2D',
  { skip: !process.env.V2_REVIEW_ROOT, timeout: 25000 },
  async () => {
    const server = await serve(process.env.V2_REVIEW_ROOT),
      browser = await launch();
    const page = await browser.newPage({
      viewport: { width: 390, height: 844 },
      isMobile: true,
      hasTouch: true,
    });
    try {
      await mapFixture(page);
      await page.goto(server.base);
      await expect(page.locator('.map-back .parentButton')).toBeVisible();
      for (const [width, height] of [
        [320, 640],
        [390, 844],
        [768, 1024],
      ]) {
        await page.setViewportSize({ width, height });
        await expect
          .poll(async () =>
            page
              .locator('.map-back .parentButton, .zoom-controls .parentButton')
              .evaluateAll((buttons) =>
                buttons.map((button) => {
                  const b = button.getBoundingClientRect(),
                    icon = button.querySelector('.face')?.getBoundingClientRect();
                  return (
                    b.width >= 44 &&
                    b.height >= 44 &&
                    !!icon &&
                    Math.abs(icon.left + icon.width / 2 - (b.left + b.width / 2)) < 1 &&
                    Math.abs(icon.top + icon.height / 2 - (b.top + b.height / 2)) < 1
                  );
                }),
              ),
          )
          .toEqual([true, true, true, true]);
      }
      await page.setViewportSize({ width: 390, height: 844 });
      await page.getByText('3D', { exact: true }).click();
      await expect(page.getByText(/3D 暂不可用，已恢复 2D/)).toBeVisible();
      await expect(page.locator('iframe')).toHaveCount(0);
      await page.screenshot({ path: artifacts + '/v2-map-recovery.png', fullPage: true });
    } finally {
      await browser.close();
      await server.close();
    }
  },
);

test('管理员日志搜索和车辆控制携带正确参数', { timeout: 30000 }, async () => {
  const server = await serve('admin/dist'),
    browser = await launch();
  const page = await browser.newPage();
  page.setDefaultTimeout(5000);
  let query = '',
    command;
  const order = {
    id: 'ops-order',
    state: 'NEEDS_ATTENTION',
    companyId: 'jade',
    pickup: 'A',
    dropoff: 'B',
    passengers: 1,
    amountMinor: 600,
    paidMinor: 0,
    createdAt: 1,
    offers: [],
    telemetry: {
      assistanceRequired: true,
      obstruction: {
        waitingSeconds: 65,
        thresholdSeconds: 60,
        explanation: 'MTR 前方轨道占用',
        blockers: [{ vehicleId: '101', orderId: 'return-order', returning: true }],
      },
    },
  };
  try {
    await page.route('**/api/**', async (route) => {
      const request = route.request(),
        url = new URL(request.url());
      let data = [];
      if (url.pathname === '/api/me') data = { user, demo: false };
      if (url.pathname === '/api/admin/system_logs') {
        query = url.search;
        data = [
          {
            id: 'log',
            created_at: 1700000000000,
            channel: 'mod',
            event: 'command_finished',
            request_id: 'alpha',
            command: 'taxi.quote',
            result: 'timeout',
            explanation: '模组未在时限内响应',
          },
        ];
      }
      if (url.pathname === '/api/admin/orders') data = [order];
      if (url.pathname === '/api/admin/orders/ops-order/events') data = { items: [] };
      if (url.pathname === '/api/admin/orders/ops-order/control') {
        command = request.postDataJSON();
        assert.equal(request.headers()['x-csrf-token'], user.csrf);
        data = { ok: true };
      }
      await route.fulfill({ json: data });
    });
    await page.goto(server.base + '/#/system_logs');
    await expect(page.getByText('模组未在时限内响应', { exact: true })).toBeVisible();
    await page.getByLabel('搜索系统日志').fill('alpha');
    await page.getByLabel('搜索系统日志').press('Enter');
    await expect.poll(() => new URLSearchParams(query).get('q')).toBe('alpha');
    await page.screenshot({ path: artifacts + '/system-log-filters.png', fullPage: true });
    await page.getByText('订单与调度', { exact: true }).first().click();
    await page.getByText('查看行程', { exact: true }).click();
    await expect(page.getByText('前方占用：等待调度协助', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: '开门并保持停车', exact: true }).click();
    await page.locator('.ant-modal-confirm-btns .ant-btn-primary').click();
    await expect.poll(() => command?.action).toBe('open_doors');
    await page.getByText('查看行程', { exact: true }).click();
    await page.getByRole('button', { name: '核验并清除此空载回库车', exact: true }).click();
    await page.locator('.ant-modal-confirm-btns .ant-btn-primary').click();
    await expect
      .poll(() => command)
      .toEqual({ action: 'clear_blocker', targetOrderId: 'return-order' });
  } catch (error) {
    await page.screenshot({ path: artifacts + '/operations-error.png', fullPage: true });
    console.error((await page.locator('body').innerText()).slice(-2200));
    throw error;
  } finally {
    await browser.close();
    await server.close();
  }
});
