// Offline integration check for the actual admin template and assets. No subscription or credentials are used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const assets = path.join(root, 'public/assets/external-subscriptions');
const host = 'http://admin.fixture.test';
const api = '/api/v2/fixture-admin/external-subscription';
const template = fs.readFileSync(path.join(root, 'resources/views/admin/external-subscriptions.blade.php'), 'utf8')
  .replaceAll('{{ $title }}', 'Fixture Xboard').replaceAll('{{ $secure_path }}', 'fixture-admin');
const at = Date.UTC(2026, 0, 2, 3, 4, 5) / 1000;
const makeNode = (id, name, health_status, latency_ms, extra = {}) => ({
  id, name, type: 'anytls', selected: true, supported: true, present: true,
  health_status, latency_ms, last_check_at: at, error: null, ...extra,
});

async function run() {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_BINARY ? { executablePath: process.env.CHROME_BINARY } : {}) });
  try {
    const page = await browser.newPage({ viewport: { width: 1360, height: 1000 }, locale: 'zh-CN', timezoneId: 'UTC' });
    await page.clock.install();
    const pageErrors = [];
    const requests = [];
    const sources = [
      { id: 1, name: 'Enabled source', enabled: true, available_nodes_count: 6, selected_nodes_count: 5, last_synced_at: at, last_error: null },
      { id: 2, name: 'Disabled source', enabled: false, available_nodes_count: 1, selected_nodes_count: 1, last_synced_at: at, last_error: null },
    ];
    const nodes = {
      1: [
        makeNode(1, 'Online AnyTLS', 'online', 123),
        makeNode(2, 'Offline', 'offline', 999),
        makeNode(3, 'Pending', 'pending', 999),
        makeNode(4, 'Unknown', 'unknown', 999),
        makeNode(5, 'New selected', 'unknown', null, { last_check_at: null }),
        makeNode(6, 'Unselected', 'online', 999, { selected: false }),
        makeNode(7, '<img src=x onerror=alert(1)>', 'online', 999, { selected: false, supported: false, error: 'Cannot convert this fixture' }),
      ],
      2: [makeNode(8, 'Disabled node', 'online', 999)],
    };
    page.on('pageerror', error => pageErrors.push(error.message));
    await page.addInitScript(() => {
      localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer fixture-only', expire: null }));
    });
    await page.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== host) return route.abort();
      if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: template });
      for (const file of ['app.js', 'app.css']) {
        if (url.pathname === '/assets/external-subscriptions/' + file) {
          return route.fulfill({ path: path.join(assets, file), contentType: file.endsWith('.js') ? 'text/javascript' : 'text/css' });
        }
      }
      if (!url.pathname.startsWith(api + '/')) return route.fulfill({ status: 404 });
      requests.push({ path: url.pathname, method: request.method() });
      let data;
      if (url.pathname === api + '/fetch') data = sources;
      else if (url.pathname === api + '/nodes') data = nodes[url.searchParams.get('id')];
      else if (url.pathname === api + '/select' && request.method() === 'POST') {
        const input = request.postDataJSON();
        for (const node of nodes[input.id]) {
          const selected = input.node_ids.includes(node.id);
          if (selected !== node.selected) {
            node.selected = selected;
            node.health_status = 'unknown';
            node.latency_ms = null;
            node.last_check_at = null;
          }
        }
        sources.find(source => source.id === input.id).selected_nodes_count = input.node_ids.length;
        data = true;
      } else throw new Error('Unexpected admin request: ' + request.method() + ' ' + url.pathname);
      return route.fulfill({ json: { data } });
    });

    await page.goto(host);
    await page.getByRole('checkbox', { name: '下发 Online AnyTLS', exact: true }).waitFor();
    const row = name => page.getByRole('checkbox', { name: '下发 ' + name, exact: true }).locator('../..');
    const result = name => row(name).locator('.node-health-result');
    assert.equal(await result('Online AnyTLS').innerText(), '123 ms');
    assert.equal(await row('Online AnyTLS').locator('.protocol').innerText(), 'ANYTLS');
    assert.match(await row('Online AnyTLS').locator('.node-health').getAttribute('title'), /面板服务器经节点到检测站点的请求耗时，非本机延迟/);
    assert.match(await row('Online AnyTLS').locator('.node-health-time').innerText(), /2026.*03:04:05/);
    assert.equal(await result('Offline').innerText(), '离线');
    assert.equal(await result('Pending').innerText(), '待复查');
    assert.equal(await result('Unknown').innerText(), '暂无有效结果');
    assert.equal(await result('New selected').innerText(), '待检测');
    assert.equal(await result('Unselected').innerText(), '未勾选');
    assert.equal(await result('<img src=x onerror=alert(1)>').innerText(), '不检测');
    assert.equal(await row('<img src=x onerror=alert(1)>').locator('input').isDisabled(), true);
    assert.equal(await page.locator('#nodes-list img').count(), 0);
    assert.equal((await page.locator('#nodes-list').innerText()).includes('999 ms'), false);
    assert.match(await page.locator('.health-note').innerText(), /每小时自动测活、测延迟/);
    assert.match(await page.locator('.health-note').innerText(), /刷新列表只更新显示/);
    if (process.env.XBOARD_ADMIN_SCREENSHOT) await page.screenshot({ path: process.env.XBOARD_ADMIN_SCREENSHOT, fullPage: true });

    // Unsaved selection must not claim that a new probe has already happened.
    await page.getByRole('searchbox').fill('Unselected');
    await row('Unselected').locator('input').check();
    assert.equal(await result('Unselected').innerText(), '未勾选');
    const beforeDirtyRefresh = requests.length;
    await page.clock.fastForward(31000);
    assert.equal(requests.length, beforeDirtyRefresh, 'Background reads pause for unsaved selection.');
    await page.getByRole('button', { name: '保存勾选', exact: true }).click();
    await page.waitForFunction(() => document.getElementById('selection-note').textContent.includes('勾选已保存'));
    assert.equal(await result('Unselected').innerText(), '待检测');
    assert.equal(await row('Unselected').locator('input').isChecked(), true);
    assert.equal(await page.getByRole('searchbox').inputValue(), 'Unselected', 'Saving selection preserves the current search.');
    await page.getByRole('searchbox').fill('');

    // Periodic refresh consumes the latest cached result and only uses read endpoints.
    nodes[1][0].latency_ms = 87;
    const beforeRefresh = requests.length;
    await page.clock.fastForward(31000);
    await page.waitForFunction(() => document.querySelector('.node-health-result')?.textContent === '87 ms');
    assert.deepEqual(requests.slice(beforeRefresh).map(request => request.path), [api + '/fetch', api + '/nodes']);

    await page.getByRole('button', { name: /Disabled source/ }).click();
    await page.getByRole('checkbox', { name: '下发 Disabled node', exact: true }).waitFor();
    assert.equal(await result('Disabled node').innerText(), '订阅源已停用');
    assert.equal((await page.locator('#nodes-list').innerText()).includes('999 ms'), false);
    assert.equal(requests.filter(request => request.method === 'POST').length, 1, 'Saving selection is the only write; no probe calls.');
    await page.setViewportSize({ width: 390, height: 844 });
    assert.ok(await page.locator('.table-scroll').evaluate(el => el.scrollWidth > el.clientWidth), 'Narrow screens scroll the table horizontally.');
    assert.deepEqual(pageErrors, []);
    console.log('Admin latency: cached measurement, all health/selection states, save/read flow, hourly explanation, refresh behavior, mobile table and safe text verified.');
  } finally { await browser.close(); }
}

run().catch(error => { console.error(error.stack); process.exitCode = 1; });
