<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="same-origin">
  <title>全局 DNS 证书配置 · {{ $title }}</title>
  <link rel="stylesheet" href="/assets/node-dns/settings-v1.css">
  <script defer src="/assets/node-dns/settings-v1.js"></script>
</head>
<body data-api-base="/api/v2/{{ $secure_path }}/dns-settings">
  <header class="topbar">
    <a class="brand" href="/{{ $secure_path }}"><span class="brand-mark" aria-hidden="true">X</span><span>{{ $title }}</span></a>
    <div class="header-actions"><a href="/{{ $secure_path }}">返回节点后台</a><button id="sign-out" class="text-button" type="button" hidden>切换账号</button></div>
  </header>
  <main>
    <section class="page-heading">
      <p class="eyebrow">NODE CERTIFICATES</p>
      <h1>全局 DNS 证书配置</h1>
      <p class="subheading">保存一份 DNS 凭据，让需要申请证书的节点直接复用。</p>
    </section>

    <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>
    <section id="loading" class="loading-state" aria-live="polite"><span class="spinner" aria-hidden="true"></span><p>正在检查管理员登录状态…</p></section>

    <section id="login-panel" class="card login-panel" hidden>
      <h2>登录管理员账号</h2>
      <p class="muted">使用原 Xboard 管理员账号。已在原后台登录时，会自动复用登录状态。</p>
      <form id="login-form">
        <label for="login-email">邮箱</label>
        <input id="login-email" type="email" autocomplete="username" required>
        <label for="login-password">密码</label>
        <input id="login-password" type="password" autocomplete="current-password" required>
        <button class="button primary" type="submit">登录并管理配置</button>
      </form>
    </section>

    <section id="workspace" class="layout" hidden>
      <section class="card settings-panel" aria-labelledby="settings-title">
        <div class="card-heading">
          <div><h2 id="settings-title">默认 DNS 参数</h2><p id="saved-at" class="muted small">尚未保存</p></div>
          <span id="saved-state" class="badge">尚未配置</span>
        </div>
        <form id="settings-form" autocomplete="off">
          <div class="form-grid">
            <div>
              <label for="dns-provider">DNS 提供商</label>
              <input id="dns-provider" name="dns_provider" value="cloudflare" maxlength="64" required autocomplete="off" spellcheck="false" aria-describedby="provider-help">
              <p id="provider-help" class="field-help">Cloudflare 填写 <code>cloudflare</code>；也可填写节点端支持的其他提供商名称。</p>
            </div>
            <div>
              <label for="dns-email">默认通知邮箱</label>
              <input id="dns-email" name="email" type="email" maxlength="254" required autocomplete="off" placeholder="用于证书申请和续期通知">
              <p class="field-help">使用全局配置的节点会复用这个邮箱。</p>
            </div>
          </div>

          <div class="secret-heading"><label for="dns-env">环境变量（API 密钥）</label><span id="secret-state" class="secret-state">尚未保存密钥</span></div>
          <textarea id="dns-env" name="dns_env_input" rows="6" maxlength="20000" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" placeholder="CF_API_TOKEN=你的 API Token" aria-describedby="env-help"></textarea>
          <p id="env-help" class="field-help">每行一项 <code>KEY=VALUE</code>。首次配置或更换提供商时需要填写；保存后不回显密钥。</p>

          <div id="conflict-panel" class="conflict-panel" hidden>
            <p>其他页面已修改了配置。本页输入仍保留，请先读取最新配置，再核对并保存。</p>
            <button id="resolve-conflict" class="button" type="button">保留输入并读取最新配置</button>
          </div>

          <footer class="form-footer">
            <div><p id="dirty-note" class="save-note" aria-live="polite">当前配置已保存</p><p class="muted small">修改后，引用全局配置的节点会获取新参数。</p></div>
            <div class="footer-actions"><button id="reload-settings" class="button" type="button">读取最新配置</button><button id="save-settings" class="button primary" type="submit">保存全局配置</button></div>
          </footer>
        </form>
      </section>

      <aside class="usage-panel" aria-labelledby="usage-title">
        <h2 id="usage-title">节点里怎么使用</h2>
        <ol>
          <li>添加或编辑节点，打开「高级设置 → TLS」。</li>
          <li>证书模式选择 <code>dns-01</code>，开启「使用全局 DNS 配置」。</li>
          <li>确认这个节点的证书域名，保存节点。</li>
        </ol>
        <p>每个节点的证书域名单独设置，DNS 凭据需要拥有相应域名的权限。</p>
        <p>现有节点保留原配置。需要单独账号时，在节点里关闭全局配置并填写独立参数。</p>
        <div class="privacy-note"><strong>密钥只需保存一次</strong><p>凭据加密保存到面板数据库。此页面不会读取或显示已保存的密钥；留空即可保留原值。</p></div>
      </aside>
    </section>
  </main>
</body>
</html>
