// Offline browser regression for the actual DNS settings template and assets.
// All accounts, tokens and environment values below are synthetic fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const host = 'http://dns.fixture.test';
const api = '/api/v2/fixture-admin/dns-settings';
const authKey = 'XBOARD_ACCESS_TOKEN';
const template = fs.readFileSync(path.join(root, 'resources/views/admin/dns-settings.blade.php'), 'utf8')
  .replaceAll('{{ $title }}', 'Fixture Xboard').replaceAll('{{ $secure_path }}', 'fixture-admin');
const metadata = overrides => ({ configured: true, dns_provider: 'cloudflare', email: 'admin@example.test',
  has_dns_env: true, updated_at: '2026-11-13T03:04:05+00:00', revision: 4, ...overrides });
const responseSecret = 'fixture-response-secret-must-never-be-rendered';

async function fixture(browser, options = {}) {
  const context = await browser.newContext({ viewport: { width: 1360, height: 1000 }, locale: 'zh-CN', timezoneId: 'UTC' });
  const page = await context.newPage();
  const model = { settings: metadata(options.settings), fetchStatus: options.fetchStatus || 200,
    saveMode: null, posts: [], reads: [], loginRequests: [], pageErrors: [],
    login: { is_admin: true, auth_data: 'Bearer fixture-new-admin' } };
  page.on('pageerror', error => model.pageErrors.push(error.message));
  if (options.auth !== false) await page.addInitScript(({ key }) => {
    localStorage.setItem(key, JSON.stringify({ value: 'Bearer fixture-admin', expire: null }));
  }, { key: authKey });
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== host) return route.abort();
    if (url.pathname === '/fixture-admin/dns-settings') return route.fulfill({ contentType: 'text/html', body: template });
    for (const file of ['settings-v1.js', 'settings-v1.css']) {
      if (url.pathname === '/assets/node-dns/' + file) return route.fulfill({
        contentType: file.endsWith('.js') ? 'text/javascript' : 'text/css', path: path.join(root, 'public/assets/node-dns', file),
      });
    }
    if (url.pathname === api + '/fetch') {
      model.reads.push(request.headers().authorization);
      return route.fulfill({ status: model.fetchStatus,
        json: model.fetchStatus === 200 ? { data: { ...model.settings, dns_env: { CF_API_TOKEN: responseSecret } } } : { message: responseSecret } });
    }
    if (url.pathname === api + '/save' && request.method() === 'POST') {
      const input = request.postDataJSON();
      model.posts.push(input);
      if (model.saveMode === 'abort') return route.abort('failed');
      if (model.saveMode) return route.fulfill({ status: model.saveMode, json: { message: responseSecret, input } });
      if (input.revision !== model.settings.revision) return route.fulfill({ status: 409, json: { message: responseSecret } });
      model.settings = metadata({ dns_provider: input.dns_provider, email: input.email,
        revision: model.settings.revision + 1, updated_at: '2026-11-13T04:05:06+00:00' });
      return route.fulfill({ json: { data: { ...model.settings, dns_env: { CF_API_TOKEN: responseSecret } } } });
    }
    if (url.pathname === '/api/v2/passport/auth/login' && request.method() === 'POST') {
      model.loginRequests.push(request.postDataJSON());
      return route.fulfill({ json: { data: model.login } });
    }
    return route.fulfill({ status: 404 });
  });
  await page.goto(host + '/fixture-admin/dns-settings');
  return { page, model, context };
}

async function idle(page) {
  await page.waitForFunction(() => !document.getElementById('dns-provider').disabled
    && document.getElementById('save-settings').textContent === '保存全局配置');
}

async function notice(page, expression) {
  await page.waitForFunction(source => new RegExp(source).test(document.getElementById('notice').textContent), expression.source);
  await idle(page);
}

async function storage(page) {
  return page.evaluate(() => ({ local: { ...localStorage }, session: { ...sessionStorage } }));
}

async function assertSecretSafety(page, secrets) {
  const text = await page.locator('body').innerText();
  const stored = JSON.stringify(await storage(page));
  for (const secret of [responseSecret, ...secrets]) {
    assert.equal(text.includes(secret), false, 'A secret must never appear in rendered text.');
    assert.equal(stored.includes(secret), false, 'DNS secrets and passwords must never enter browser storage.');
  }
}

async function login(page, email = 'admin@example.test', password = 'fixture-password') {
  await page.locator('#login-email').fill(email);
  await page.locator('#login-password').fill(password);
  await page.locator('#login-form button').click();
}

async function run() {
  const browser = await chromium.launch({ headless: true,
    ...(process.env.CHROME_BINARY ? { executablePath: process.env.CHROME_BINARY } : {}) });
  try {
    // Existing metadata is visible, credentials are not returned to inputs or storage.
    {
      const { page, model, context } = await fixture(browser);
      try {
        await page.locator('#workspace').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#dns-env').inputValue(), '');
        assert.match(await page.locator('#secret-state').innerText(), /密钥已保存.*不回显/);
        assert.equal(await page.locator('#save-settings').isDisabled(), true);
        assert.deepEqual(model.reads, ['Bearer fixture-admin']);
        await assertSecretSafety(page, []);

        await page.locator('#dns-email').fill('changed@example.test');
        assert.match(await page.locator('#dirty-note').innerText(), /未保存/);
        await page.locator('#save-settings').click();
        await notice(page, /配置已保存/);
        assert.deepEqual(model.posts[0], { dns_provider: 'cloudflare', email: 'changed@example.test', revision: 4 });
        assert.equal(await page.locator('#dns-env').inputValue(), '');
        assert.equal(await page.locator('#save-settings').isDisabled(), true);

        const token = 'fixture-only-token==suffix';
        await page.locator('#dns-env').fill('CF_API_TOKEN=' + token + '\nCF_ZONE_API_TOKEN=fixture-zone=tail');
        await page.locator('#save-settings').click();
        await page.waitForFunction(() => document.getElementById('dns-env').value === '');
        await idle(page);
        assert.deepEqual(model.posts[1].dns_env, { CF_API_TOKEN: token, CF_ZONE_API_TOKEN: 'fixture-zone=tail' });
        assert.equal(model.posts[1].revision, 5);
        await assertSecretSafety(page, [token, 'fixture-zone=tail']);

        await page.locator('#dns-env').fill('CF_API_TOKEN=fixture-one\nCF_API_TOKEN=fixture-two');
        await page.locator('#save-settings').click();
        await notice(page, /重复名称/);
        assert.equal(model.posts.length, 2, 'Duplicate keys are rejected before submitting.');
        assert.match(await page.locator('#dns-env').inputValue(), /fixture-two/);

        await page.locator('#dns-env').fill('CF_API_TOKEN=' + '测'.repeat(1366));
        await page.locator('#save-settings').click();
        await notice(page, /4096 字节/);
        assert.equal(model.posts.length, 2, 'Value limit is measured in UTF-8 bytes.');

        await page.locator('#dns-env').fill('');
        await page.locator('#dns-provider').fill('alidns');
        await page.locator('#save-settings').click();
        await notice(page, /首次配置或更换/);
        assert.equal(model.posts.length, 2, 'Changing provider requires new credentials.');

        // A manual refresh updates metadata without discarding a dirty draft.
        await page.locator('#dns-provider').fill('cloudflare');
        await page.locator('#dns-email').fill('draft@example.test');
        await page.locator('#dns-env').fill('CF_API_TOKEN=fixture-retained-draft');
        model.settings.email = 'other-admin@example.test';
        await page.locator('#reload-settings').click();
        await notice(page, /本页输入保持不变/);
        assert.equal(await page.locator('#dns-email').inputValue(), 'draft@example.test');
        assert.equal(await page.locator('#dns-env').inputValue(), 'CF_API_TOKEN=fixture-retained-draft');
        await assertSecretSafety(page, ['fixture-retained-draft']);

        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'Mobile layout must not overflow horizontally.');
        if (process.env.XBOARD_DNS_SETTINGS_SCREENSHOT) await page.screenshot({ path: process.env.XBOARD_DNS_SETTINGS_SCREENSHOT, fullPage: true });
        assert.deepEqual(model.pageErrors, []);
      } finally { await context.close(); }
    }

    // First save, provider validation and explicit conflict resolution.
    {
      const { page, model, context } = await fixture(browser, {
        settings: { configured: false, email: '', has_dns_env: false, updated_at: null, revision: 0 },
      });
      try {
        await page.locator('#workspace').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#dns-provider').inputValue(), 'cloudflare');
        assert.match(await page.locator('#secret-state').innerText(), /尚未保存/);
        await page.locator('#dns-email').fill('first@example.test');
        await page.locator('#save-settings').click();
        await notice(page, /首次配置或更换/);
        assert.equal(model.posts.length, 0);
        await page.locator('#dns-provider').fill('Cloudflare');
        await page.locator('#dns-env').fill('CF_API_TOKEN=fixture-first==token');
        await page.locator('#save-settings').click();
        await notice(page, /小写/);
        assert.equal(model.posts.length, 0);
        await page.locator('#dns-provider').fill('cloudflare');
        await page.locator('#save-settings').click();
        await page.waitForFunction(() => document.getElementById('dns-env').value === '');
        await idle(page);
        assert.equal(model.posts[0].revision, 0);
        assert.deepEqual(model.posts[0].dns_env, { CF_API_TOKEN: 'fixture-first==token' });

        // Another administrator saves after this browser last fetched metadata.
        model.settings.revision = 7;
        model.settings.email = 'concurrent@example.test';
        await page.locator('#dns-email').fill('my-draft@example.test');
        await page.locator('#dns-env').fill('CF_API_TOKEN=fixture-conflict-secret');
        await page.locator('#save-settings').click();
        await notice(page, /其他页面修改/);
        assert.equal(await page.locator('#conflict-panel').isVisible(), true);
        assert.equal(await page.locator('#save-settings').isDisabled(), true);
        assert.equal(await page.locator('#dns-env').inputValue(), 'CF_API_TOKEN=fixture-conflict-secret');
        assert.equal(model.posts.length, 2, 'A conflict must not auto-retry a write.');
        await page.locator('#resolve-conflict').click();
        await notice(page, /本页输入保持不变/);
        assert.equal(await page.locator('#conflict-panel').isVisible(), false);
        assert.equal(await page.locator('#dns-email').inputValue(), 'my-draft@example.test');
        assert.equal(await page.locator('#dns-env').inputValue(), 'CF_API_TOKEN=fixture-conflict-secret');
        assert.equal(model.posts.length, 2, 'Reading latest metadata is not an approval to save.');
        await page.locator('#save-settings').click();
        await page.waitForFunction(() => document.getElementById('dns-env').value === '');
        await idle(page);
        assert.equal(model.posts[2].revision, 7);
        await assertSecretSafety(page, ['fixture-first==token', 'fixture-conflict-secret']);
        assert.deepEqual(model.pageErrors, []);
      } finally { await context.close(); }
    }

    // Server/network errors preserve input and never render an error body.
    {
      const { page, model, context } = await fixture(browser);
      try {
        await page.locator('#workspace').waitFor({ state: 'visible' });
        await page.locator('#dns-env').fill('CF_API_TOKEN=fixture-failed-secret');
        model.saveMode = 500;
        await page.locator('#save-settings').click();
        await notice(page, /请求失败（500）/);
        assert.equal(await page.locator('#dns-env').inputValue(), 'CF_API_TOKEN=fixture-failed-secret');
        await assertSecretSafety(page, ['fixture-failed-secret']);
        model.saveMode = 'abort';
        await page.locator('#save-settings').click();
        await notice(page, /网络连接失败/);
        assert.equal(await page.locator('#dns-env').inputValue(), 'CF_API_TOKEN=fixture-failed-secret');

        model.saveMode = 403;
        await page.locator('#save-settings').click();
        await notice(page, /登录已失效/);
        assert.equal(await page.locator('#workspace').isVisible(), false);
        assert.equal(await page.locator('#login-panel').isVisible(), true);
        assert.equal((await storage(page)).local[authKey], undefined);
        assert.equal(await page.locator('#dns-env').inputValue(), 'CF_API_TOKEN=fixture-failed-secret');
        model.saveMode = null;
        await login(page);
        await notice(page, /管理员登录成功/);
        assert.equal(await page.locator('#dns-env').inputValue(), 'CF_API_TOKEN=fixture-failed-secret');
        assert.equal(await page.locator('#login-password').inputValue(), '');
        assert.equal(model.reads.at(-1), 'Bearer fixture-new-admin');
        await assertSecretSafety(page, ['fixture-failed-secret', 'fixture-password']);
        const saved = await storage(page);
        assert.deepEqual(Object.keys(saved.local), [authKey]);
        assert.deepEqual(saved.session, {});

        page.once('dialog', dialog => dialog.dismiss());
        await page.locator('#sign-out').click();
        assert.equal(await page.locator('#workspace').isVisible(), true);
        assert.equal(await page.locator('#dns-env').inputValue(), 'CF_API_TOKEN=fixture-failed-secret');
        page.once('dialog', dialog => dialog.accept());
        await page.locator('#sign-out').click();
        await notice(page, /已退出/);
        assert.equal(await page.locator('#login-panel').isVisible(), true);
        assert.equal(await page.locator('#dns-env').inputValue(), '');
        assert.equal((await storage(page)).local[authKey], undefined);
        assert.deepEqual(model.pageErrors, []);
      } finally { await context.close(); }
    }

    // Read authorization is authoritative: a normal user and revoked administrator cannot read settings.
    {
      const { page, model, context } = await fixture(browser, { auth: false });
      try {
        await page.locator('#login-panel').waitFor({ state: 'visible' });
        assert.equal(model.reads.length, 0);
        model.login = { is_admin: false, auth_data: 'Bearer fixture-user' };
        await login(page, 'user@example.test');
        await notice(page, /不是管理员/);
        assert.equal(model.reads.length, 0);
        assert.equal(await page.locator('#login-password').inputValue(), '');
        assert.deepEqual(await storage(page), { local: {}, session: {} });
        model.login = { is_admin: true, auth_data: 'Bearer fixture-revoked-admin' };
        model.fetchStatus = 403;
        await login(page);
        await notice(page, /登录已失效/);
        assert.equal(await page.locator('#workspace').isVisible(), false);
        assert.equal((await storage(page)).local[authKey], undefined, 'Do not persist a token before the protected read succeeds.');
        await assertSecretSafety(page, ['fixture-password']);
        assert.deepEqual(model.pageErrors, []);
      } finally { await context.close(); }
    }
    console.log('DNS settings: authentication/403, masked metadata, secret storage exclusion, preserve/replace, first save, UTF-8/duplicate validation, conflict resolution, network failure retention and mobile layout verified.');
  } finally { await browser.close(); }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
