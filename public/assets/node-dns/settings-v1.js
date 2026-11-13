(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const apiBase = document.body.dataset.apiBase;
  const tokenKey = 'XBOARD_ACCESS_TOKEN';
  const state = { token: '', saved: null, busy: false, conflict: false };
  const dirty = () => !!state.saved && ($('dns-provider').value.trim() !== state.saved.dns_provider
    || $('dns-email').value.trim() !== state.saved.email || $('dns-env').value.trim() !== '');

  class RequestFailure extends Error {
    constructor(message, status = 0) { super(message); this.status = status; }
  }

  function notify(message, error = false) {
    $('notice').textContent = message;
    $('notice').className = `notice${error ? ' error' : ''}`;
    $('notice').hidden = !message;
  }

  function forgetToken(token) {
    try {
      const stored = JSON.parse(localStorage.getItem(tokenKey) || 'null');
      if (stored?.value === token) localStorage.removeItem(tokenKey);
    } catch (_) { /* Browser storage is optional. */ }
  }

  function showLogin(clearDraft = false) {
    state.token = '';
    $('workspace').hidden = true;
    $('loading').hidden = true;
    $('login-panel').hidden = false;
    $('sign-out').hidden = true;
    $('login-password').value = '';
    if (clearDraft) {
      state.saved = null;
      state.conflict = false;
      $('dns-provider').value = 'cloudflare';
      $('dns-email').value = '';
      $('dns-env').value = '';
    }
    renderActions();
  }

  function renderActions() {
    const changed = dirty();
    $('dirty-note').textContent = changed ? '有未保存的修改' : state.saved?.configured ? '当前配置已保存' : '填写参数后保存，即可供节点复用';
    $('dirty-note').className = `save-note${changed ? ' unsaved' : ''}`;
    $('conflict-panel').hidden = !state.conflict;
    document.querySelectorAll('button, input, textarea').forEach(control => { control.disabled = state.busy; });
    $('save-settings').disabled = state.busy || !state.saved || !changed || state.conflict;
    $('save-settings').textContent = state.busy && !$('workspace').hidden ? '正在处理…' : '保存全局配置';
  }

  async function run(action) {
    if (state.busy) return;
    state.busy = true;
    renderActions();
    try { await action(); }
    catch (error) {
      notify(error instanceof RequestFailure ? error.message : '操作未完成，请稍后重试。输入的参数仍保留在本页。', true);
    } finally {
      state.busy = false;
      renderActions();
    }
  }

  async function request(path, data, authenticated = true) {
    const requestToken = state.token;
    const headers = { Accept: 'application/json', 'Content-Language': 'zh-CN' };
    if (authenticated) headers.Authorization = requestToken;
    if (data !== undefined) headers['Content-Type'] = 'application/json';
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    let response;
    try {
      response = await fetch(path, {
        method: data === undefined ? 'GET' : 'POST', headers,
        body: data === undefined ? undefined : JSON.stringify(data),
        credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
      });
      if (!response.ok) {
        // Error bodies can echo submitted input. Never render or log them.
        if (authenticated && (response.status === 401 || response.status === 403)) {
          forgetToken(requestToken);
          showLogin();
          throw new RequestFailure('登录已失效或当前账号不是管理员，请重新登录。未保存的输入仍保留在本页。', response.status);
        }
        if (response.status === 409) throw new RequestFailure('配置已被其他页面修改，请读取最新配置后再保存。本页输入已保留。', 409);
        if (!authenticated && [400, 401, 403, 422].includes(response.status)) throw new RequestFailure('登录失败，请检查管理员邮箱和密码。', response.status);
        if (response.status === 422) throw new RequestFailure('参数未通过校验，请检查提供商、邮箱及环境变量格式。输入已保留。', 422);
        if (response.status === 429) throw new RequestFailure('操作过于频繁，请稍后再试。输入已保留。', 429);
        throw new RequestFailure(`请求失败（${response.status}），请稍后重试。输入已保留。`, response.status);
      }
      const payload = await response.json();
      return payload.data;
    } catch (error) {
      if (error instanceof RequestFailure) throw error;
      throw new RequestFailure('网络连接失败或响应异常，请稍后重试。输入已保留。');
    } finally { window.clearTimeout(timeout); }
  }

  function safeSettings(data) {
    if (!data || typeof data.configured !== 'boolean' || typeof data.has_dns_env !== 'boolean'
      || typeof data.dns_provider !== 'string' || typeof data.email !== 'string'
      || !Number.isSafeInteger(data.revision) || data.revision < 0) {
      throw new RequestFailure('配置响应格式异常，请重新读取配置。输入已保留。');
    }
    // Keep only public metadata, even if a future response accidentally adds other fields.
    return { configured: data.configured, dns_provider: data.dns_provider || 'cloudflare', email: data.email,
      has_dns_env: data.has_dns_env, revision: data.revision,
      updated_at: typeof data.updated_at === 'string' || typeof data.updated_at === 'number' ? data.updated_at : null };
  }

  function renderSaved() {
    const saved = state.saved;
    $('saved-state').textContent = saved.configured ? '已配置' : '尚未配置';
    $('saved-state').className = `badge${saved.configured ? ' configured' : ''}`;
    const date = saved.updated_at == null ? null : new Date(typeof saved.updated_at === 'number' ? saved.updated_at * 1000 : saved.updated_at);
    $('saved-at').textContent = date && Number.isFinite(date.getTime())
      ? `最近保存：${date.toLocaleString('zh-CN', { hour12: false })}` : '尚未保存';
    $('secret-state').textContent = saved.has_dns_env ? '密钥已保存 · 不回显' : '尚未保存密钥';
    $('secret-state').className = `secret-state${saved.has_dns_env ? ' saved' : ''}`;
    $('env-help').textContent = saved.has_dns_env
      ? '每行一项 KEY=VALUE。留空保留已保存的密钥；输入新内容会替换整组环境变量，更换提供商时必须重新填写。'
      : '每行一项 KEY=VALUE。首次配置需要填写；保存成功后输入框会清空，密钥不回显。';
  }

  function applySettings(data, preserveDraft = false) {
    state.saved = safeSettings(data);
    state.conflict = false;
    if (!preserveDraft) {
      $('dns-provider').value = state.saved.dns_provider;
      $('dns-email').value = state.saved.email;
      $('dns-env').value = '';
    }
    renderSaved();
    $('loading').hidden = true;
    $('login-panel').hidden = true;
    $('workspace').hidden = false;
    $('sign-out').hidden = false;
    renderActions();
  }

  async function loadSettings(preserveDraft = false) {
    applySettings(await request(`${apiBase}/fetch`), preserveDraft);
  }

  function parseEnvironment(text) {
    const result = Object.create(null);
    const encode = new TextEncoder();
    for (const line of text.split(/\r?\n/)) {
      if (line.trim() === '') continue;
      const separator = line.indexOf('=');
      if (separator < 1) throw new RequestFailure('环境变量格式不正确，请每行填写 KEY=VALUE。');
      const key = line.slice(0, separator).trim();
      const value = line.slice(separator + 1);
      if (!/^[A-Za-z_][A-Za-z0-9_]{0,63}$/.test(key)) throw new RequestFailure('环境变量名称只能包含字母、数字和下划线，且不能以数字开头。');
      if (Object.hasOwn(result, key)) throw new RequestFailure('环境变量存在重复名称，请保留每个名称的一项配置。');
      if (value.trim() === '' || /[\x00-\x1f\x7f]/.test(value) || encode.encode(value).length > 4096) {
        throw new RequestFailure('环境变量值不能为空、不能包含控制字符，且每项不能超过 4096 字节。');
      }
      result[key] = value;
    }
    if (Object.keys(result).length > 64 || encode.encode(JSON.stringify(result)).length > 16384) {
      throw new RequestFailure('环境变量最多 64 项，合计不能超过 16 KiB。');
    }
    return result;
  }

  $('settings-form').addEventListener('submit', event => {
    event.preventDefault();
    if (!state.saved || state.conflict) return;
    void run(async () => {
      const provider = $('dns-provider').value.trim();
      const email = $('dns-email').value.trim();
      if (!/^[a-z][a-z0-9_-]{0,63}$/.test(provider)) throw new RequestFailure('DNS 提供商名称请使用小写字母、数字、下划线或连字符，并以字母开头。');
      if (!email || email.length > 254 || !$('dns-email').validity.valid) throw new RequestFailure('请填写有效的通知邮箱。');
      const environment = parseEnvironment($('dns-env').value);
      const hasInput = Object.keys(environment).length > 0;
      if (!hasInput && (!state.saved.has_dns_env || provider !== state.saved.dns_provider)) {
        throw new RequestFailure('首次配置或更换 DNS 提供商时，请填写对应的 API 环境变量。');
      }
      const data = { dns_provider: provider, email, revision: state.saved.revision };
      if (hasInput) data.dns_env = environment;
      let saved;
      try { saved = await request(`${apiBase}/save`, data); }
      catch (error) {
        if (error instanceof RequestFailure && error.status === 409) state.conflict = true;
        throw error;
      }
      // Only a successful, valid response clears the draft. Never refill a saved secret.
      applySettings(saved);
      notify('全局 DNS 配置已保存。引用此配置的节点会获取更新后的参数。');
    });
  });

  for (const id of ['dns-provider', 'dns-email', 'dns-env']) $(id).addEventListener('input', renderActions);
  for (const id of ['reload-settings', 'resolve-conflict']) $(id).addEventListener('click', () => {
    void run(async () => {
      const preserve = dirty();
      await loadSettings(preserve);
      notify(preserve ? '已读取最新保存状态，本页输入保持不变。请核对后再保存。' : '已读取最新配置。');
    });
  });

  $('login-form').addEventListener('submit', event => {
    event.preventDefault();
    void run(async () => {
      let auth;
      try {
        auth = await request('/api/v2/passport/auth/login', {
          email: $('login-email').value.trim(), password: $('login-password').value,
        }, false);
      } finally { $('login-password').value = ''; }
      if (auth?.is_admin !== true && auth?.is_admin !== 1) throw new RequestFailure('此账号不是管理员，无法管理全局 DNS 配置。');
      if (typeof auth.auth_data !== 'string' || !/^Bearer\s+\S+$/i.test(auth.auth_data)) throw new RequestFailure('登录响应异常，请重新登录。');
      state.token = auth.auth_data;
      await loadSettings(dirty());
      try { localStorage.setItem(tokenKey, JSON.stringify({ value: state.token, time: Date.now(), expire: Date.now() + 259200000 })); }
      catch (_) { /* The authenticated in-memory session works without browser storage. */ }
      notify('管理员登录成功。');
    });
  });

  $('sign-out').addEventListener('click', () => {
    if (dirty() && !window.confirm('当前修改尚未保存，切换账号会清空本页输入。确定继续吗？')) return;
    forgetToken(state.token);
    showLogin(true);
    notify('已退出当前浏览器的管理登录。');
  });
  window.addEventListener('beforeunload', event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('pagehide', () => { $('dns-env').value = ''; $('login-password').value = ''; });

  void run(async () => {
    try {
      const saved = JSON.parse(localStorage.getItem(tokenKey) || 'null');
      if (saved && typeof saved.value === 'string' && /^Bearer\s+\S+$/i.test(saved.value)
        && (saved.expire == null || saved.expire > Date.now())) state.token = saved.value;
    } catch (_) { /* Use the login form when storage is unavailable. */ }
    if (!state.token) { state.saved = null; return showLogin(); }
    try { await loadSettings(); }
    catch (error) { showLogin(); throw error; }
  });
})();
