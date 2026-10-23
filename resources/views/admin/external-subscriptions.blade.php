<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="same-origin">
  <title>第三方订阅 · {{ $title }}</title>
  <link rel="stylesheet" href="/assets/external-subscriptions/app.css?v=1">
  <script defer src="/assets/external-subscriptions/app.js?v=1"></script>
</head>
<body data-api-base="/api/v2/{{ $secure_path }}/external-subscription">
  <header class="topbar">
    <a class="brand" href="/{{ $secure_path }}"><span class="brand-mark">X</span><span>{{ $title }}</span></a>
    <div class="header-actions"><a href="/{{ $secure_path }}">返回原后台</a><button id="sign-out" class="text-button" hidden>切换账号</button></div>
  </header>
  <main>
    <section class="page-heading">
      <div><p class="eyebrow">SUBSCRIPTION SOURCES</p><h1>第三方订阅</h1><p class="subheading">接入订阅，选好节点，再向有效套餐用户下发。</p></div>
      <button id="add-source" class="button primary" hidden><span aria-hidden="true">＋</span> 添加订阅源</button>
    </section>

    <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>
    <section id="loading" class="empty-state"><span class="spinner" aria-hidden="true"></span><p>正在检查管理员登录状态…</p></section>

    <section id="login-panel" class="login-panel card" hidden>
      <p class="eyebrow">ADMIN ACCESS</p><h2>登录管理员账号</h2>
      <p class="muted">使用原 Xboard 管理员邮箱和密码。已在原后台登录时，会自动复用登录状态。</p>
      <form id="login-form">
        <label for="login-email">邮箱</label><input id="login-email" type="email" autocomplete="username" required placeholder="admin@example.com">
        <label for="login-password">密码</label><input id="login-password" type="password" autocomplete="current-password" minlength="8" required>
        <button class="button primary" type="submit">登录并管理订阅</button>
      </form>
    </section>

    <section id="workspace" hidden>
      <div class="summary-grid">
        <div class="summary-card"><span>订阅源</span><strong id="source-total">0</strong></div>
        <div class="summary-card"><span>已启用</span><strong id="enabled-total">0</strong></div>
        <div class="summary-card"><span>正在下发的节点</span><strong id="published-total">0</strong></div>
        <div class="summary-note"><span class="status-dot"></span><p>仅下发已勾选节点<br><span>新增节点默认不勾选</span></p></div>
      </div>

      <div class="layout">
        <aside class="sources-panel card">
          <div class="panel-heading"><h2>订阅源</h2><button id="reload-sources" class="text-button">刷新</button></div>
          <div id="sources-list"></div>
          <p class="aside-note">上游地址仅在添加或修改时输入，保存后不再回显。</p>
        </aside>

        <section class="nodes-panel card">
          <div id="no-source" class="empty-state"><div class="empty-icon" aria-hidden="true">↗</div><h2>先添加一个订阅源</h2><p>输入第三方订阅地址，获取节点后即可勾选下发。</p><button id="empty-add" class="button primary">添加订阅源</button></div>
          <div id="source-detail" hidden>
            <div class="detail-heading">
              <div><div class="title-line"><h2 id="source-name"></h2><span id="source-status" class="badge"></span></div><p id="source-sync-time" class="muted small"></p></div>
              <div class="source-actions"><button id="sync-source" class="button">↻ 同步节点</button><button id="edit-source" class="button">编辑</button><button id="toggle-source" class="button"></button><button id="delete-source" class="text-button danger">删除</button></div>
            </div>
            <div id="source-error" class="inline-warning" hidden></div>
            <div class="selection-tools">
              <label class="search-label"><span class="sr-only">搜索节点</span><input id="node-search" type="search" placeholder="搜索节点名称或协议…" autocomplete="off"></label>
              <button id="select-filtered" class="button small-button">勾选筛选结果</button><button id="clear-selection" class="text-button">清空勾选</button>
            </div>
            <div class="table-scroll"><table><thead><tr><th class="check-col"><span class="sr-only">下发</span></th><th>节点名称</th><th>协议</th><th>状态</th></tr></thead><tbody id="nodes-list"></tbody></table></div>
            <div id="nodes-empty" class="empty-state compact" hidden><p></p></div>
            <footer class="selection-footer"><div><strong id="selection-count">已勾选 0 个节点</strong><p id="selection-note" class="muted small">保存勾选后，用户更新订阅时生效。</p></div><button id="save-selection" class="button primary">保存勾选</button></footer>
          </div>
        </section>
      </div>
      <p class="footnote">本功能只负责订阅下发。用户使用的是上游节点，流量及连接条件仍由上游服务决定；到期后停止下发不能撤回用户已导入的节点。</p>
    </section>
  </main>

  <dialog id="source-dialog">
    <form id="source-form">
      <div class="dialog-heading"><h2 id="dialog-title">添加订阅源</h2><button type="button" id="close-dialog" class="text-button" aria-label="关闭">✕</button></div>
      <label for="source-form-name">订阅源名称</label><input id="source-form-name" required maxlength="100" placeholder="例如：主力订阅">
      <label for="source-form-url">第三方订阅地址</label><input id="source-form-url" type="url" maxlength="4096" autocomplete="off" spellcheck="false" placeholder="https://…">
      <p id="url-help" class="muted small">保存后地址不会回显。新订阅源默认停用，请先同步并勾选节点。</p>
      <div class="dialog-footer"><button type="button" id="cancel-dialog" class="button">取消</button><button type="submit" class="button primary">保存订阅源</button></div>
    </form>
  </dialog>
</body>
</html>
