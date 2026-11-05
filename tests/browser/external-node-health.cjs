// Offline integration check: run against the actual PHP-generated stock Vue bundle, with fixture API responses only.
// PLAYWRIGHT_MODULE, CHROME_BINARY and XBOARD_HEALTH_BUNDLE can point to local development tools/artifacts.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const assets = path.join(root, 'public/assets/external-subscriptions');
const bundle = fs.readFileSync(process.env.XBOARD_HEALTH_BUNDLE || path.join(assets, 'xboard-dashboard-health-v2.js'));
const host = 'http://dashboard.fixture.test';
const template = fs.readFileSync(path.join(root, 'theme/Xboard/index.html'), 'utf8')
  .replace('/assets/umi.js', '/assets/external-subscriptions/xboard-dashboard-health-v2.js')
  .replace('</head>', '<link rel="stylesheet" href="/assets/external-subscriptions/node-health-v2.css"></head>');
const env = fs.readFileSync(path.join(root, 'theme/Xboard/env.js'), 'utf8').replace('http://127.0.0.1:8000/', '/');
const at = Date.UTC(2026, 0, 2, 3, 4, 5) / 1000;
const displays = [
  ['online', true, at, '在线', 'rgb(59, 130, 246)'],
  ['offline', false, at, '离线', 'rgb(239, 68, 68)'],
  ['pending', null, at, '待复查', 'rgb(148, 163, 184)'],
  ['unknown', null, null, '未知', 'rgb(148, 163, 184)'],
];

async function run() {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_BINARY ? { executablePath: process.env.CHROME_BINARY } : {}) });
  try {
    const page = await browser.newPage({ viewport: { width: 1250, height: 900 }, locale: 'zh-CN', timezoneId: 'UTC' });
    const pageErrors = [];
    let listRequests = 0;
    let onlineLatency = 123;
    page.on('pageerror', (error) => pageErrors.push(error.message));
    await page.addInitScript(() => {
      localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer fixture-only', time: Date.now(), expire: null }));
    });
    await page.route('**/*', async (route) => {
      const url = new URL(route.request().url());
      if (url.origin !== host) return route.abort();
      if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: template });
      if (url.pathname === '/env.js') return route.fulfill({ contentType: 'text/javascript', body: env });
      if (url.pathname.endsWith('/xboard-dashboard-health-v2.js')) return route.fulfill({ contentType: 'text/javascript', body: bundle });
      if (url.pathname.endsWith('/node-health-v2.js')) return route.fulfill({ path: path.join(assets, 'node-health-v2.js'), contentType: 'text/javascript' });
      if (url.pathname.endsWith('/node-health-v2.css')) return route.fulfill({ path: path.join(assets, 'node-health-v2.css'), contentType: 'text/css' });
      let data = {};
      if (url.pathname.endsWith('/user/info')) data = { email: 'fixture@example.invalid', uuid: 'fixture-uuid', plan_id: 1 };
      if (url.pathname.endsWith('/user/server/fetch')) {
        listRequests++;
        data = [
          { id: 1, name: 'Native heartbeat', type: 'trojan', rate: 1, is_online: 1, last_check_at: at, tags: [] },
          ...displays.map(([health_status, is_online, last_check_at], i) => ({
            id: -i - 1, name: 'External ' + health_status, external: true, type: 'trojan', rate: null, tags: [], health_status, is_online, last_check_at,
            latency_ms: health_status === 'online' ? onlineLatency : 999,
          })),
        ];
      }
      if (url.pathname.startsWith('/api/')) return route.fulfill({ json: { data } });
      return route.fulfill({ status: 404, body: '' });
    });

    await page.goto(host + '/#/node');
    await page.locator('button.xb-external-health').first().waitFor();
    assert.equal(listRequests, 1);
    assert.equal(await page.locator('button.xb-external-health').count(), 4);
    const native = page.getByText('Native heartbeat', { exact: true }).locator('..');
    assert.equal(await native.locator('.rounded-full.bg-blue-500').count(), 1);
    assert.equal(await native.locator('button.xb-external-health').count(), 0);
    for (const [state, , timestamp, label, color] of displays) {
      const button = page.locator(`button.xb-external-health[data-health="${state}"]`);
      assert.equal(await button.locator('.xb-external-health-dot').evaluate(el => getComputedStyle(el).backgroundColor), color);
      assert.equal(await button.locator('.xb-external-health-latency').count(), state === 'online' ? 1 : 0);
      if (state === 'online') assert.equal(await button.innerText(), '123 ms');
      await button.hover();
      const tip = page.getByRole('tooltip');
      await tip.waitFor();
      const message = await tip.innerText();
      assert.ok(message.startsWith(label + '\n'));
      if (timestamp) assert.match(message, /最近检测.*2026.*03:04:05/);
      else assert.match(message, /尚无检测记录/);
      assert.match(message, /面板服务器经节点到检测站点的请求耗时，非本机延迟/);
      assert.equal(message.includes('999 ms'), false);
      await page.keyboard.press('Escape');
      assert.equal(await tip.count(), 0);
    }
    const pending = page.locator('[data-health="pending"]');
    await pending.focus();
    await page.getByRole('tooltip').waitFor();
    await page.keyboard.press('Escape');
    await pending.click();
    await page.getByRole('tooltip').waitFor();
    if (process.env.XBOARD_HEALTH_SCREENSHOT) await page.screenshot({ path: process.env.XBOARD_HEALTH_SCREENSHOT });
    for (const invalidLatency of [null, -1, '123', 1.5]) {
      onlineLatency = invalidLatency;
      await page.reload();
      await page.locator('button.xb-external-health[data-health="online"]').waitFor();
      assert.equal(await page.locator('.xb-external-health-latency').count(), 0);
    }
    assert.deepEqual(pageErrors, []);
    assert.equal(listRequests, 5, 'Only loading the node list should read the user API.');
    console.log('Stock dashboard: native fallback, four external states, measured latency, hidden invalid/stale values, localized time, hover/focus/click and Escape verified.');
  } finally { await browser.close(); }
}

run().catch(error => { console.error(error.message); process.exitCode = 1; });
