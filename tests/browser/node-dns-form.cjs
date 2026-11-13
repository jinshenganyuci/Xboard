// Uses the actual PHP-generated stock React admin bundle. Every API response is synthetic and offline.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const publicRoot = path.join(root, 'public');
const bundle = fs.readFileSync(process.env.XBOARD_ADMIN_DNS_BUNDLE || path.join(publicRoot, 'assets/node-dns/admin-dns-v1.js'));
const origin = 'http://admin-dns.fixture.test';
const clone = value => JSON.parse(JSON.stringify(value));
const legacySecret = 'synthetic-node-specific-secret';
const base = { type: 'anytls', name: '', rate: '1', show: true, host: 'node.example.invalid', port: 443, server_port: 443,
  tags: [], group_ids: [], route_ids: [], excludes: [], ips: [], parent_id: null, machine_id: null, enabled: true,
  protocol_settings: { tls: { server_name: 'sni.example.invalid', allow_insecure: false } },
  custom_outbounds: [], custom_routes: [], transfer_enable: 0, u: 0, d: 0 };
let nodes = [
  { ...clone(base), id: 101, name: 'Legacy DNS', cert_config: { cert_mode: 'dns', domain: 'legacy.example.invalid', email: 'legacy@example.invalid', dns_provider: 'cloudflare', dns_env: { CF_API_TOKEN: legacySecret } } },
  { ...clone(base), id: 102, name: 'Global DNS', cert_config: { cert_mode: 'dns', domain: 'global.example.invalid', use_global_dns: true } },
];
let settings = { configured: true, dns_provider: 'cloudflare', email: 'global@example.invalid', has_dns_env: true, revision: 1 };
let settingsStatus = 200;
const writes = [];
const pageErrors = [];
const html = `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">
<script>window.settings={base_url:'/',secure_path:'fixture-admin',title:'DNS fixture',version:'test'};</script>
<link rel="stylesheet" href="/assets/admin/assets/index-DiYa-_z_.css">
<link rel="stylesheet" href="/assets/node-dns/node-dns-form-v1.css">
<script src="/assets/admin/locales/en-US.js"></script><script src="/assets/admin/locales/zh-CN.js"></script>
</head><body><div id="root"></div><script type="module" src="/assets/node-dns/admin-dns-v1.js"></script></body></html>`;

async function run() {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_BINARY ? { executablePath: process.env.CHROME_BINARY } : {}) });
  let page;
  const requestPaths = [];
  const consoleErrors = [];
  try {
    page = await browser.newPage({ viewport: { width: 1360, height: 1000 }, locale: 'zh-CN' });
    page.on('pageerror', error => pageErrors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text().slice(0, 500)); });
    page.on('request', request => { requestPaths.push(new URL(request.url()).pathname); });
    await page.addInitScript(() => {
      localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer fixture-only', time: Date.now(), expire: null }));
      localStorage.setItem('i18nextLng', 'zh-CN');
    });
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname === '/fixture-admin') return route.fulfill({ contentType: 'text/html', body: html });
      if (url.pathname === '/assets/node-dns/admin-dns-v1.js') return route.fulfill({ contentType: 'text/javascript', body: bundle });
      if (url.pathname.startsWith('/assets/admin/') || url.pathname.startsWith('/assets/node-dns/')) {
        const file = path.resolve(publicRoot, '.' + url.pathname);
        if (file.startsWith(publicRoot + path.sep) && fs.existsSync(file)) {
          const contentType = file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream';
          return route.fulfill({ path: file, contentType });
        }
      }
      if (url.pathname.endsWith('/dns-settings/fetch')) return route.fulfill({ status: settingsStatus, json: settingsStatus === 200 ? { data: settings } : { message: 'Fixture settings unavailable' } });
      if (url.pathname.endsWith('/user/info')) return route.fulfill({ json: { data: { id: 1, email: 'admin@example.invalid', is_admin: true } } });
      if (url.pathname.endsWith('/plugin/getPlugins')) return route.fulfill({ json: { data: [] } });
      if (url.pathname.endsWith('/server/manage/getNodes')) return route.fulfill({ json: { data: nodes } });
      if (url.pathname.endsWith('/server/manage/save')) {
        const payload = route.request().postDataJSON();
        writes.push(payload);
        const id = payload.id || 200 + writes.length;
        nodes = [...nodes.filter(node => node.id !== id), { ...clone(base), ...clone(payload), id }];
        return route.fulfill({ json: { data: true } });
      }
      if (url.pathname.endsWith('/server/manage/copy')) {
        const payload = route.request().postDataJSON();
        const source = nodes.find(node => node.id === payload.id);
        nodes.push({ ...clone(source), id: 500, name: 'Copied global DNS' });
        return route.fulfill({ json: { data: true } });
      }
      if (url.pathname.startsWith('/api/')) return route.fulfill({ json: { data: url.pathname.includes('/server/') ? [] : {} } });
      return route.fulfill({ status: 404, body: '' });
    });

    const visit = async () => { await page.goto(origin + '/fixture-admin#/server/manage'); await page.getByRole('button', { name: /添加节点|Add Node/i }).waitFor(); };
    const dialog = () => page.getByRole('dialog').last();
    const checkbox = () => page.getByRole('checkbox', { name: '使用全局 DNS 配置', exact: true });
    const advanced = async () => { await dialog().getByRole('button', { name: /高级设置|Advanced/i }).click(); await page.getByRole('dialog').last().getByRole('combobox').first().waitFor(); };
    const chooseDns = async () => { await dialog().getByRole('combobox').first().click(); await page.getByRole('option', { name: 'dns-01 (ACME)', exact: true }).click(); await checkbox().waitFor(); };
    const saveAdvanced = async () => { await dialog().getByRole('button', { name: /^(Save|保存)$/ }).click(); };
    const cancel = async () => { await dialog().getByRole('button', { name: /^(Cancel|取消)$/ }).click(); };
    const edit = async name => {
      const row = page.getByRole('row').filter({ hasText: name });
      await row.getByRole('button', { name: /操作|Actions/i }).click();
      await page.getByRole('menuitem', { name: /^(编辑|Edit)$/ }).click();
      await dialog().locator('input[name="name"]').waitFor();
    };
    const create = async (name, host, sni = '') => {
      await page.getByRole('button', { name: /添加节点|Add Node/i }).click();
      await dialog().getByRole('combobox').first().click();
      await page.getByRole('option', { name: 'AnyTLS', exact: true }).click();
      await dialog().locator('input[name="name"]').fill(name);
      await dialog().locator('input[name="host"]').fill(host);
      await dialog().locator('input[name="port"]').fill('443');
      await dialog().locator('input[name="server_port"]').fill('443');
      if (sni) await dialog().locator('input[name="tls.server_name"]').fill(sni);
    };

    await visit();
    await edit('Legacy DNS');
    await advanced();
    await checkbox().waitFor();
    assert.equal(await checkbox().isChecked(), false, 'An existing node must remain independent.');
    assert.equal(await dialog().locator('textarea[placeholder^="CF_API_TOKEN="]').inputValue(), 'CF_API_TOKEN=' + legacySecret);
    await checkbox().check();
    assert.equal(await dialog().locator('textarea[placeholder^="CF_API_TOKEN="]').count(), 0);
    assert.match(await page.locator('[data-node-dns]').innerText(), /global@example\.invalid/);
    await checkbox().uncheck();
    assert.equal(await dialog().locator('textarea[placeholder^="CF_API_TOKEN="]').inputValue(), 'CF_API_TOKEN=' + legacySecret, 'Only this node\'s own values may be restored.');
    await checkbox().check();
    await dialog().getByRole('combobox').first().click();
    await page.getByRole('option', { name: 'http-01 (ACME)', exact: true }).click();
    await chooseDns();
    assert.equal(await checkbox().isChecked(), false);
    assert.equal(await dialog().locator('textarea[placeholder^="CF_API_TOKEN="]').inputValue(), 'CF_API_TOKEN=' + legacySecret, 'Changing certificate modes must keep the independent text consistent with its saved value.');
    await checkbox().check();
    await cancel();
    await advanced();
    assert.equal(await checkbox().isChecked(), false, 'Cancelling advanced TLS must restore the original choice.');
    await checkbox().check();
    if (process.env.XBOARD_DNS_FORM_SCREENSHOT) await page.screenshot({ path: process.env.XBOARD_DNS_FORM_SCREENSHOT });
    await saveAdvanced();
    await dialog().getByRole('button', { name: /^(提交|Submit)$/ }).click();
    await page.waitForFunction(() => !document.querySelector('[role="dialog"]'));
    assert.deepEqual(writes.at(-1).cert_config, { cert_mode: 'dns', domain: 'legacy.example.invalid', use_global_dns: true });
    assert.equal(JSON.stringify(writes.at(-1)).includes(legacySecret), false);

    await edit('Legacy DNS'); await advanced();
    assert.equal(await checkbox().isChecked(), true, 'Reopening a saved node must retain inheritance.');
    await checkbox().uncheck();
    await page.getByText(/切换为独立配置后，需要填写该节点/).waitFor();
    assert.equal(await dialog().locator('textarea[placeholder^="CF_API_TOKEN="]').inputValue(), '', 'Global credentials must never be copied into independent fields.');
    assert.equal(await dialog().locator('input[name="cert_config.dns_provider"]').inputValue(), '');
    await cancel(); await cancel();

    await create('New DNS', 'node.example.invalid', 'tls.example.invalid');
    await advanced(); await chooseDns();
    await page.waitForFunction(() => document.querySelector('input[aria-label="使用全局 DNS 配置"]')?.checked);
    assert.equal(await dialog().locator('input[name="cert_config.domain"]').inputValue(), 'tls.example.invalid');
    await saveAdvanced();
    await dialog().getByRole('combobox').first().click();
    await page.getByRole('option', { name: 'Trojan', exact: true }).click();
    await advanced();
    assert.equal(await checkbox().isChecked(), true, 'Switching node protocols must retain the explicit certificate choice.');
    assert.equal(await dialog().locator('input[name="cert_config.domain"]').inputValue(), 'tls.example.invalid');
    await saveAdvanced();
    await dialog().getByRole('button', { name: /^(提交|Submit)$/ }).click();
    await page.waitForFunction(() => !document.querySelector('[role="dialog"]'));
    assert.deepEqual(writes.at(-1).cert_config, { cert_mode: 'dns', domain: 'tls.example.invalid', use_global_dns: true });

    const globalRow = page.getByRole('row').filter({ hasText: 'Global DNS' });
    await globalRow.getByRole('button', { name: /操作|Actions/i }).click();
    await page.getByRole('menuitem', { name: /^(复制|Copy)$/ }).click();
    await page.getByRole('row').filter({ hasText: 'Copied global DNS' }).waitFor();
    await edit('Copied global DNS'); await advanced();
    assert.equal(await checkbox().isChecked(), true);
    await cancel(); await cancel();

    await create('IP node', '192.0.2.10'); await advanced(); await chooseDns();
    assert.equal(await dialog().locator('input[name="cert_config.domain"]').inputValue(), '');
    await cancel(); await cancel();
    settings = { ...settings, configured: false, has_dns_env: false };
    await create('Unconfigured', 'fallback.example.invalid'); await advanced(); await chooseDns();
    await page.getByText(/尚未配置全局 DNS/).waitFor();
    assert.equal(await checkbox().isChecked(), false);
    assert.equal(await checkbox().isDisabled(), true);
    assert.equal(await dialog().locator('input[name="cert_config.domain"]').inputValue(), 'fallback.example.invalid');
    await cancel(); await cancel();

    settingsStatus = 503;
    await create('Unavailable', 'retry.example.invalid'); await advanced(); await chooseDns();
    await page.getByText(/无法读取全局 DNS 配置/).waitFor();
    assert.equal(await checkbox().isChecked(), false);
    await cancel(); await cancel();
    assert.equal(writes.length, 2, 'Opening, toggling and cancelling must not save a node or global settings.');
    assert.deepEqual(pageErrors, []);
    console.log('Stock admin DNS form: inheritance, independent credentials, cancel/save/edit/copy/protocol switch, domain suggestion, unavailable settings and secret-free payloads verified.');
  } catch (error) {
    // This browser is entirely fixture-backed: still redact the synthetic credential in diagnostics.
    const redact = value => String(value).replaceAll(legacySecret, '[redacted]');
    const body = page ? await page.locator('body').innerText().catch(() => '') : '';
    console.error(JSON.stringify({ url: page?.url(), pageErrors, consoleErrors, requests: requestPaths.slice(-30), body: redact(body).slice(0, 3500) }, null, 2));
    if (page && process.env.XBOARD_DNS_FORM_SCREENSHOT) await page.screenshot({ path: process.env.XBOARD_DNS_FORM_SCREENSHOT }).catch(() => {});
    throw error;
  } finally { await browser.close(); }
}

run().catch(error => { console.error(error.stack); process.exitCode = 1; });
