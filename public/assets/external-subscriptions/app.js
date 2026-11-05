(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const apiBase = document.body.dataset.apiBase;
  const tokenKey = 'XBOARD_ACCESS_TOKEN';
  const state = { token: '', sources: [], sourceId: null, nodes: [], selected: new Set(), saved: new Set(), busy: false, editing: null, epoch: 0, refreshing: false, refreshQueued: false, refreshAbort: null };
  const current = () => state.sources.find((source) => source.id === state.sourceId);
  const eligible = (node) => node.present && node.supported;
  const protocolLabel = (node) => node.type === 'hysteria' ? 'Hysteria2' : (node.type || '—');
  const dirty = () => state.selected.size !== state.saved.size || [...state.selected].some((id) => !state.saved.has(id));
  const filtered = () => {
    const term = $('node-search').value.trim().toLocaleLowerCase();
    return state.nodes.filter((node) => node.present && `${node.name} ${node.type} ${protocolLabel(node)}`.toLocaleLowerCase().includes(term));
  };

  function invalidateRefresh() {
    state.epoch += 1;
    state.refreshAbort?.abort();
  }

  const canAutoRefresh = () => !!state.token && !document.hidden && !$('workspace').hidden
    && !state.busy && !$('source-dialog').open && !dirty();

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function notify(message, error = false) {
    $('notice').textContent = message;
    $('notice').className = `notice${error ? ' error' : ''}`;
    $('notice').hidden = !message;
  }

  function setBusy(value) {
    state.busy = value;
    document.querySelectorAll('button').forEach((button) => { button.disabled = value; });
    document.querySelectorAll('#nodes-list input').forEach((checkbox) => {
      checkbox.disabled = value || checkbox.dataset.unavailable === 'true';
    });
    $('node-search').disabled = value;
    $('source-form-name').disabled = value;
    $('source-form-url').disabled = value;
  }

  async function run(action) {
    if (state.busy) return;
    invalidateRefresh();
    setBusy(true);
    try { await action(); } catch (error) { notify(error.message || '操作失败，请稍后重试。', true); }
    finally { setBusy(false); }
  }

  function showLogin(message = '') {
    invalidateRefresh();
    state.token = '';
    state.sources = [];
    state.sourceId = null;
    state.nodes = [];
    state.selected.clear();
    state.saved.clear();
    $('workspace').hidden = true;
    $('login-panel').hidden = false;
    $('loading').hidden = true;
    $('add-source').hidden = true;
    $('sign-out').hidden = true;
    if (message) notify(message, true);
  }

  async function request(path, data, authenticated = true, signal) {
    const requestToken = state.token;
    const headers = { Accept: 'application/json', 'Content-Language': 'zh-CN' };
    if (authenticated) headers.Authorization = requestToken;
    if (data !== undefined) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, {
      method: data === undefined ? 'GET' : 'POST',
      headers,
      body: data === undefined ? undefined : JSON.stringify(data),
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      signal,
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      if (authenticated && (response.status === 401 || response.status === 403)) {
        if (state.token === requestToken) showLogin('登录已失效或当前账号不是管理员，请重新登录。');
        throw new Error('请使用管理员账号登录。');
      }
      const fieldError = payload.errors && Object.values(payload.errors).flat().find((value) => typeof value === 'string');
      throw new Error(fieldError || payload.message || `请求失败（${response.status}），请稍后重试。`);
    }
    return payload.data;
  }

  const api = (action, data) => request(`${apiBase}/${action}`, data);

  function renderSources() {
    $('source-total').textContent = state.sources.length;
    $('enabled-total').textContent = state.sources.filter((source) => source.enabled).length;
    $('published-total').textContent = state.sources.filter((source) => source.enabled).reduce((sum, source) => sum + source.selected_nodes_count, 0);
    const list = $('sources-list');
    list.replaceChildren();
    if (!state.sources.length) list.append(element('p', 'source-list-empty', '还没有订阅源。添加后，先同步节点再勾选下发。'));
    for (const source of state.sources) {
      const button = element('button', `source-card${source.id === state.sourceId ? ' active' : ''}`);
      button.type = 'button';
      button.setAttribute('aria-pressed', String(source.id === state.sourceId));
      const title = element('span', 'source-card-name');
      title.append(element('span', '', source.name), element('span', `badge${source.enabled ? ' enabled' : ''}`, source.enabled ? '已启用' : '已停用'));
      button.append(title, element('span', 'source-card-summary', `${source.available_nodes_count} 个可用 · 已勾选 ${source.selected_nodes_count} 个`));
      button.addEventListener('click', () => {
        if (source.id === state.sourceId || !allowDiscard()) return;
        run(() => loadNodes(source.id));
      });
      list.append(button);
    }
    $('source-detail').hidden = !current();
    $('no-source').hidden = !!current();
  }

  function renderDetail() {
    const source = current();
    if (!source) return;
    $('source-name').textContent = source.name;
    $('source-status').textContent = source.enabled ? '已启用' : '已停用';
    $('source-status').className = `badge${source.enabled ? ' enabled' : ''}`;
    $('toggle-source').textContent = source.enabled ? '停用下发' : '启用下发';
    const date = source.last_synced_at ? new Date(typeof source.last_synced_at === 'number' ? source.last_synced_at * 1000 : source.last_synced_at) : null;
    $('source-sync-time').textContent = date && !Number.isNaN(date.getTime()) ? `上次同步：${date.toLocaleString('zh-CN', { hour12: false })}` : '尚未同步，请先获取第三方节点。';
    $('source-error').textContent = source.last_error ? `最近同步失败：${source.last_error}` : '';
    $('source-error').hidden = !source.last_error;
    renderNodes();
  }

  function renderNodes() {
    const list = $('nodes-list');
    list.replaceChildren();
    const nodes = filtered();
    for (const node of nodes) {
      const row = element('tr', `${state.selected.has(node.id) ? 'selected' : ''}${eligible(node) ? '' : ' unavailable'}`);
      const checkboxCell = element('td');
      const checkbox = element('input');
      checkbox.type = 'checkbox';
      checkbox.checked = state.selected.has(node.id);
      checkbox.disabled = state.busy || !eligible(node);
      checkbox.dataset.unavailable = String(!eligible(node));
      checkbox.setAttribute('aria-label', `下发 ${node.name}`);
      checkbox.addEventListener('change', () => {
        invalidateRefresh();
        if (checkbox.checked) state.selected.add(node.id); else state.selected.delete(node.id);
        row.classList.toggle('selected', checkbox.checked);
        renderSelection();
      });
      checkboxCell.append(checkbox);
      const nameCell = element('td', 'node-name', node.name);
      if (node.error) nameCell.append(element('span', 'node-error', node.error));
      const statusCell = element('td');
      statusCell.append(element('span', `badge${eligible(node) ? ' enabled' : ' warning'}`, !node.present ? '上游已移除' : node.supported ? '可下发' : '不支持'));
      row.append(checkboxCell, nameCell, element('td', 'protocol', protocolLabel(node)), statusCell, renderHealth(node));
      list.append(row);
    }
    $('nodes-empty').hidden = nodes.length > 0;
    $('nodes-empty').querySelector('p').textContent = state.nodes.length ? '没有匹配的节点，试试其他关键词。' : '暂无节点。点击「同步节点」从第三方订阅获取。';
    renderSelection();
  }

  function renderHealth(node) {
    const cell = element('td', 'node-health');
    const active = eligible(node) && current()?.enabled && state.saved.has(node.id);
    const checked = typeof node.last_check_at === 'number' && node.last_check_at > 0
      ? new Date(node.last_check_at * 1000) : null;
    const checkedText = checked && Number.isFinite(checked.getTime())
      ? `最近检测：${checked.toLocaleString('zh-CN', { hour12: false })}` : '尚无检测记录';
    const status = active && ['online', 'offline', 'pending'].includes(node.health_status) ? node.health_status : 'unknown';
    const latency = status === 'online' && Number.isSafeInteger(node.latency_ms) && node.latency_ms >= 0 ? node.latency_ms : null;
    let label = '待检测';
    if (!eligible(node)) label = '不检测';
    else if (!current()?.enabled) label = '订阅源已停用';
    else if (!state.saved.has(node.id)) label = '未勾选';
    else if (status === 'online') label = latency === null ? '在线 · 暂无延迟' : `${latency} ms`;
    else if (status === 'offline') label = '离线';
    else if (status === 'pending') label = '待复查';
    else if (checked) label = '暂无有效结果';
    cell.dataset.health = active ? status : 'inactive';
    const result = element('span', 'node-health-result', label);
    if (active) {
      const dot = element('span', 'node-health-dot');
      dot.setAttribute('aria-hidden', 'true');
      result.prepend(dot);
    }
    cell.append(result);
    if (active) {
      cell.append(element('span', 'node-health-time', checkedText));
      cell.title = `${status === 'online' ? '在线\n' : ''}${label}\n${checkedText}\n面板服务器经节点到检测站点的请求耗时，非本机延迟。`;
    }
    return cell;
  }

  function renderSelection() {
    $('selection-count').textContent = `已勾选 ${state.selected.size} 个节点 · 当前显示 ${filtered().length} 个`;
    $('selection-note').textContent = dirty() ? '有未保存的勾选，请点击保存。' : current()?.enabled ? '勾选已保存，用户更新订阅时生效。' : '保存勾选后，还需启用此订阅源才能下发。';
    $('selection-note').className = `muted small${dirty() ? ' unsaved' : ''}`;
  }

  function allowDiscard() {
    return !dirty() || window.confirm('当前勾选尚未保存，确定放弃这些更改吗？');
  }

  async function loadSources() {
    state.sources = await api('fetch');
    $('loading').hidden = true;
    $('login-panel').hidden = true;
    $('workspace').hidden = false;
    $('add-source').hidden = false;
    $('sign-out').hidden = false;
    if (!state.sources.some((source) => source.id === state.sourceId)) state.sourceId = null;
    renderSources();
  }

  async function loadNodes(id, preserveView = false) {
    const nodes = await request(`${apiBase}/nodes?id=${encodeURIComponent(id)}`);
    applyNodes(id, nodes, preserveView);
  }

  function applyNodes(id, nodes, preserveView = false) {
    const table = document.querySelector('.table-scroll');
    const sourceList = $('sources-list');
    const scroll = [table.scrollTop, table.scrollLeft, sourceList.scrollTop, sourceList.scrollLeft];
    state.sourceId = id;
    state.nodes = nodes.filter((node) => node.present);
    state.selected = new Set(state.nodes.filter((node) => node.selected && eligible(node)).map((node) => node.id));
    state.saved = new Set(state.selected);
    if (!preserveView) $('node-search').value = '';
    renderSources();
    renderDetail();
    if (preserveView) {
      [table.scrollTop, table.scrollLeft, sourceList.scrollTop, sourceList.scrollLeft] = scroll;
    }
  }

  async function refreshSnapshot() {
    if (state.refreshing) {
      state.refreshQueued = true;
      return;
    }
    if (!canAutoRefresh()) return;
    const epoch = state.epoch;
    const token = state.token;
    const sourceId = state.sourceId;
    const isCurrent = () => canAutoRefresh() && state.epoch === epoch && state.token === token && state.sourceId === sourceId;
    const controller = new AbortController();
    state.refreshing = true;
    state.refreshQueued = false;
    state.refreshAbort = controller;
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    try {
      // Only read our cached snapshot. Scheduled upstream synchronization runs on the server.
      const sources = await request(`${apiBase}/fetch`, undefined, true, controller.signal);
      if (!isCurrent()) return;
      const nextId = sources.some((source) => source.id === sourceId) ? sourceId : (sources[0]?.id ?? null);
      const nodes = nextId === null ? [] : await request(`${apiBase}/nodes?id=${encodeURIComponent(nextId)}`, undefined, true, controller.signal);
      // An administrator may start editing or switch sources while either request is in flight.
      if (!isCurrent()) return;
      state.sources = sources;
      applyNodes(nextId, nodes, true);
    } catch (_) {
      // A temporary read failure must not replace the visible snapshot or interrupt editing.
    } finally {
      window.clearTimeout(timeout);
      state.refreshAbort = null;
      state.refreshing = false;
      if (state.refreshQueued) {
        state.refreshQueued = false;
        queueMicrotask(refreshSnapshot);
      }
    }
  }

  function openSourceDialog(source = null) {
    invalidateRefresh();
    state.editing = source?.id || null;
    $('dialog-title').textContent = source ? '编辑订阅源' : '添加订阅源';
    $('source-form').reset();
    $('source-form-name').value = source?.name || '';
    $('source-form-url').required = !source;
    $('source-form-url').placeholder = source ? '留空保留原地址；输入新地址则替换' : 'https://…';
    $('url-help').textContent = source ? '原地址不回显。替换地址将停用此源并清空原勾选，请重新同步、勾选及启用。' : '保存后地址不会回显。新订阅源默认停用，请先同步并勾选节点。';
    $('source-dialog').showModal();
  }

  $('add-source').addEventListener('click', () => openSourceDialog());
  $('empty-add').addEventListener('click', () => openSourceDialog());
  $('edit-source').addEventListener('click', () => openSourceDialog(current()));
  for (const id of ['close-dialog', 'cancel-dialog']) $(id).addEventListener('click', () => $('source-dialog').close());
  $('source-dialog').addEventListener('cancel', (event) => { if (state.busy) event.preventDefault(); });
  $('source-dialog').addEventListener('close', () => { state.editing = null; invalidateRefresh(); });

  $('source-form').addEventListener('submit', (event) => {
    event.preventDefault();
    if (!allowDiscard()) return;
    const payload = { name: $('source-form-name').value.trim() };
    if (state.editing) payload.id = state.editing;
    const url = $('source-form-url').value.trim();
    if (url) payload.url = url;
    run(async () => {
      const result = await api('save', payload);
      $('source-form-url').value = '';
      $('source-dialog').close();
      await loadSources();
      await loadNodes(result.id);
      notify('订阅源已保存。请同步节点、勾选保存，再启用下发。');
    });
  });

  $('reload-sources').addEventListener('click', () => {
    if (!allowDiscard()) return;
    run(async () => { await loadSources(); if (state.sourceId || state.sources[0]) await loadNodes(state.sourceId || state.sources[0].id); });
  });
  $('sync-source').addEventListener('click', () => {
    if (!allowDiscard() || !current()) return;
    const id = state.sourceId;
    run(async () => {
      notify('正在获取上游订阅，请稍候…');
      try {
        const result = await api('sync', { id });
        notify(`同步完成：${result.total ?? 0} 个节点，可用 ${result.supported ?? 0} 个，新增 ${result.added ?? 0} 个，移除 ${result.removed ?? 0} 个。新增节点默认不勾选。`);
      } finally {
        await loadSources();
        if (state.sources.some((source) => source.id === id)) await loadNodes(id);
      }
    });
  });
  $('toggle-source').addEventListener('click', () => {
    const source = current();
    if (!source || !allowDiscard()) return;
    run(async () => {
      await api('save', { id: source.id, name: source.name, enabled: !source.enabled });
      await loadSources();
      await loadNodes(source.id);
      notify(source.enabled ? '已停用，后续订阅更新不再下发此源。' : '已启用，仅下发已保存勾选且可用的节点。');
    });
  });
  $('delete-source').addEventListener('click', () => {
    const source = current();
    if (!source || !window.confirm(`确定删除「${source.name}」及其节点和勾选记录吗？`)) return;
    run(async () => {
      await api('drop', { id: source.id });
      state.selected.clear(); state.saved.clear();
      await loadSources();
      if (state.sources[0]) await loadNodes(state.sources[0].id);
      notify('订阅源已删除。');
    });
  });
  $('node-search').addEventListener('input', renderNodes);
  $('select-filtered').addEventListener('click', () => {
    invalidateRefresh();
    filtered().filter(eligible).forEach((node) => state.selected.add(node.id));
    renderNodes();
  });
  $('clear-selection').addEventListener('click', () => { invalidateRefresh(); state.selected.clear(); renderNodes(); });
  $('save-selection').addEventListener('click', () => {
    if (!current()) return;
    const id = state.sourceId;
    run(async () => {
      await api('select', { id, node_ids: [...state.selected] });
      state.saved = new Set(state.selected);
      await loadSources();
      await loadNodes(id, true);
      notify(current()?.enabled ? '勾选已保存，用户下次更新订阅时生效。' : '勾选已保存。启用此订阅源后才会下发给用户。');
    });
  });

  $('login-form').addEventListener('submit', (event) => {
    event.preventDefault();
    run(async () => {
      const auth = await request('/api/v2/passport/auth/login', {
        email: $('login-email').value.trim(), password: $('login-password').value,
      }, false);
      $('login-password').value = '';
      if (!auth?.is_admin || typeof auth.auth_data !== 'string') throw new Error('此账号不是管理员，无法管理第三方订阅。');
      state.token = auth.auth_data;
      await loadSources();
      try { localStorage.setItem(tokenKey, JSON.stringify({ value: state.token, time: Date.now(), expire: Date.now() + 259200000 })); } catch (_) { /* Storage may be disabled; the in-memory session remains usable. */ }
      if (state.sources[0]) await loadNodes(state.sources[0].id);
      notify('管理员登录成功。');
    });
  });
  $('sign-out').addEventListener('click', () => {
    if (!allowDiscard()) return;
    try { const value = JSON.parse(localStorage.getItem(tokenKey) || 'null'); if (value?.value === state.token) localStorage.removeItem(tokenKey); } catch (_) { /* Ignore unavailable browser storage. */ }
    showLogin();
    notify('已退出当前浏览器的管理登录。');
  });
  window.addEventListener('beforeunload', (event) => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
  document.addEventListener('visibilitychange', () => {
    invalidateRefresh();
    if (!document.hidden) void refreshSnapshot();
  });
  window.setInterval(refreshSnapshot, 30000);

  run(async () => {
    try {
      const saved = JSON.parse(localStorage.getItem(tokenKey) || 'null');
      if (saved && typeof saved.value === 'string' && /^Bearer\s+\S+$/i.test(saved.value) && (saved.expire == null || saved.expire > Date.now())) state.token = saved.value;
    } catch (_) { /* Fall through to the normal administrator login form. */ }
    if (!state.token) return showLogin();
    try {
      await loadSources();
      if (state.sources[0]) await loadNodes(state.sources[0].id);
    } catch (error) {
      showLogin();
      throw error;
    }
  });
})();
