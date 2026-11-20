import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

async function source(file) {
  return ts.transpileModule(await readFile(new URL('../' + file, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
}

test('API 空响应、代理 HTML、截断 JSON 和超时显示可读错误', async () => {
  const code = await source('shared/http.ts');
  for (const [raw, status] of [
    ['', 200],
    ['<html>Bad Gateway</html>', 502],
    ['{"offers":', 200],
  ]) {
    const exports = {};
    runInNewContext(code, {
      exports,
      AbortController,
      setTimeout,
      clearTimeout,
      fetch: async () => new Response(raw, { status }),
    });
    await assert.rejects(
      exports.apiRequest('/api/quotes', {}),
      (e) => e.message.includes('空响应或无效数据') && e.status === status,
    );
  }
  const exports = {};
  runInNewContext(code, {
    exports,
    AbortController,
    setTimeout: (fn) => setTimeout(fn, 5),
    clearTimeout,
    fetch: async (_, init) =>
      new Promise((_, reject) =>
        init.signal.addEventListener('abort', () => reject(new Error('aborted'))),
      ),
  });
  await assert.rejects(exports.apiRequest('/api/quotes', {}), /请求超时/);
});

test('BlueMap 渲染就绪、WebGL 丢失和初始化失败均可检测', async () => {
  const exports = {};
  runInNewContext(await source('shared/bluemap-health.ts'), { exports });
  function fixture({ failed = false, ready = true, native = false } = {}) {
    let check,
      now = 0,
      pagehide,
      lost;
    const reports = [];
    const renderer = {
      domElement: {
        width: 400,
        addEventListener: (_, fn) => (lost = fn),
        removeEventListener() {},
      },
      info: { render: { calls: ready ? 1 : 0 } },
      getContext: () => ({ isContextLost: () => false }),
      dispose() {},
      forceContextLoss() {},
    };
    const viewer = {
      renderer,
      map: ready ? {} : null,
      data: { superSampling: 2, loadedHiresViewDistance: 200 },
      updateLoadedMapArea() {},
    };
    const window = { bluemap: { mapViewer: viewer } };
    runInNewContext(
      exports.blueMapHealthScript(native ? 'https://taxi.test/static/bluemap-fallback.html' : ''),
      {
        window,
        document: { querySelector: () => (failed ? {} : null) },
        parent: { postMessage: (v) => reports.push(v.status) },
        location: { origin: 'https://taxi.test', replace: (value) => reports.push(value) },
        Date: { now: () => now },
        setInterval: (fn) => {
          check = fn;
          return 1;
        },
        clearInterval() {},
        addEventListener: (_, fn) => (pagehide = fn),
      },
    );
    return {
      viewer,
      reports,
      check,
      lost: () => lost(),
      timeout: () => {
        now = 21000;
        check();
      },
      cleanup: () => pagehide(),
    };
  }
  const loaded = fixture();
  assert.deepEqual(loaded.reports, ['ready']);
  assert.equal(loaded.viewer.data.superSampling, 0.75);
  assert.equal(loaded.viewer.data.loadedHiresViewDistance, 64);
  loaded.lost();
  assert.deepEqual(loaded.reports, ['ready', 'failed']);
  assert.deepEqual(fixture({ failed: true }).reports, ['failed']);
  const stalled = fixture({ ready: false });
  stalled.timeout();
  assert.deepEqual(stalled.reports, ['failed']);
  assert.deepEqual(fixture({ failed: true, native: true }).reports, [
    'https://taxi.test/static/bluemap-fallback.html',
  ]);
});
