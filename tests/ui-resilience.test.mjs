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
