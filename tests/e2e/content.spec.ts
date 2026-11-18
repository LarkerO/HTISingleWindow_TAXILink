import { test, expect } from '@playwright/test';

const notice = {
  id: 'notice-test',
  title: '夜间服务调整',
  markdown: '# 出行提醒\n\n**提前预约**\n\n<script>window.unsafe=true</script>',
  placements: '["booking","orders","profile"]',
  published: 1,
  listed: 1,
  login_required: 0,
  updated_at: 1794988800,
};
const user = {
  id: 'admin-test',
  username: '测试管理员',
  avatar: null,
  firstLogin: 1794988800,
  csrf: 'test-csrf',
  platformAdmin: true,
  memberships: [],
};

test('乘客从通知栏打开公告，正文渲染 Markdown 并隔离 HTML', async ({ page }) => {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const data =
      path === '/api/me'
        ? { user: null, demo: false }
        : path === '/api/announcements/notice-test'
          ? notice
          : path === '/api/announcements'
            ? { items: [notice], total: 1, page: 1 }
            : path === '/api/points'
              ? { items: [], groups: [] }
              : {};
    await route.fulfill({ json: data });
  });
  await page.goto('http://127.0.0.1:5173/');
  await page.getByRole('link', { name: /夜间服务调整/ }).click();
  await expect(page.getByRole('heading', { name: '出行提醒' })).toBeVisible();
  await expect(page.locator('.markdown-body strong')).toHaveText('提前预约');
  expect(await page.evaluate(() => (window as any).unsafe)).toBeUndefined();
  await page.getByRole('link', { name: '←' }).click();
  await expect(page.getByRole('heading', { name: '公告中心' })).toBeVisible();
});

test('管理端编辑公告展示范围、登录限制并保存', async ({ page }) => {
  let saved: any;
  await page.route('**/api/**', async (route) => {
    const request = route.request(),
      path = new URL(request.url()).pathname;
    if (path === '/api/admin/announcements/notice-test' && request.method() === 'PATCH') {
      saved = request.postDataJSON();
      expect(request.headers()['x-csrf-token']).toBe('test-csrf');
      await route.fulfill({ json: { id: notice.id, ...saved } });
      return;
    }
    await route.fulfill({
      json:
        path === '/api/me'
          ? { user, demo: false }
          : path === '/api/admin/announcements'
            ? [notice]
            : path === '/api/admin/summary'
              ? {}
              : [],
    });
  });
  await page.goto('http://127.0.0.1:5174/#/announcements');
  await page.getByRole('button', { name: /^编\s*辑$/ }).click();
  await page.getByLabel('标题', { exact: true }).fill('夜间服务调整（更新）');
  await page.getByLabel('需要登录查看', { exact: true }).click();
  await page.getByRole('tab', { name: '预览', exact: true }).click();
  await expect(page.locator('.markdown-body strong')).toHaveText('提前预约');
  await page.getByRole('button', { name: /^保\s*存$/ }).click();
  await expect.poll(() => saved?.title).toBe('夜间服务调整（更新）');
  expect(saved.login_required).toBe(true);
  expect(saved.placements).toEqual(['booking', 'orders', 'profile']);
});
