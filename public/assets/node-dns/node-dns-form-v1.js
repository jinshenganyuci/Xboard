// This module is mounted by the verified stock admin adapter. Never fetch DNS credentials.
const drafts = new WeakMap();
const copy = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const fieldOptions = { shouldDirty: true, shouldTouch: true };

export function beginDnsEdit(form, dnsEnvPath) {
  const path = dnsEnvPath.endsWith('.dns_env') ? dnsEnvPath.slice(0, -8) : '';
  if (!path) return;
  const original = copy(form.getValues(path));
  drafts.set(form, { path, original, independent: original?.use_global_dns === true ? null : copy(original || {}) });
}

export function cancelDnsEdit(form) {
  const draft = drafts.get(form);
  if (draft) form.setValue(draft.path, copy(draft.original), fieldOptions);
  drafts.delete(form);
}

export function commitDnsEdit(form) { drafts.delete(form); }

export function nodeDnsPayload(payload) {
  const config = payload.cert_config;
  if (config?.cert_mode !== 'dns' || config.use_global_dns !== true) return payload;
  return { ...payload, cert_config: { cert_mode: 'dns', domain: config.domain || '', use_global_dns: true } };
}

/** Suggest a certificate name only for a DNS hostname, never an IP or a URL. */
export function certificateDomain(...candidates) {
  for (const candidate of candidates) {
    if (typeof candidate !== 'string') continue;
    const name = candidate.trim().replace(/\.$/, '');
    if (name.length > 253 || !name.includes('.') || /[\s:/@?#*]/.test(name)) continue;
    if (/^[\d.]+$/.test(name)) continue;
    const labels = name.split('.');
    if (labels.every(label => /^[a-z\d](?:[a-z\d-]{0,61}[a-z\d])?$/i.test(label))) return name;
  }
  return '';
}

function useGlobal(form, path, enabled) {
  const current = form.getValues(path) || {};
  let draft = drafts.get(form);
  if (!draft || draft.path !== path) {
    draft = { path, original: copy(current), independent: current.use_global_dns === true ? null : copy(current) };
    drafts.set(form, draft);
  }
  if (enabled) {
    if (current.use_global_dns !== true) draft.independent = copy(current);
    form.setValue(path, { cert_mode: 'dns', domain: current.domain || '', use_global_dns: true }, fieldOptions);
  } else {
    form.setValue(path, {
      ...(copy(draft.independent) || {}),
      cert_mode: current.cert_mode || 'dns', domain: current.domain || '', use_global_dns: false,
    }, fieldOptions);
  }
}

function syncDnsEnvText(form, path, onChange) {
  const env = form.getValues(`${path}.dns_env`) || {};
  onChange(Object.entries(env).map(([key, value]) => `${key}=${value ?? ''}`).join('\n'));
}

export function useDnsDefaults(React, form, path, mode, fetchMetadata, onDnsEnvTextChange) {
  const h = React.createElement;
  const [metadata, setMetadata] = React.useState(null);
  const [failed, setFailed] = React.useState(false);
  const fetcher = React.useRef(fetchMetadata);
  fetcher.current = fetchMetadata;
  const usesGlobal = form.watch(`${path}.use_global_dns`);
  const domain = form.watch(`${path}.domain`);
  const sniObject = form.watch('protocol_settings.tls.server_name');
  const sniSettings = form.watch('protocol_settings.tls_settings.server_name');
  const sniLegacy = form.watch('protocol_settings.server_name');
  const host = form.watch('host');
  const nodeId = form.watch('id');

  React.useEffect(() => {
    const abort = new AbortController();
    let active = true;
    Promise.resolve().then(() => fetcher.current(abort.signal)).then(response => {
      if (!active) return;
      const value = response?.data;
      if (!value || typeof value.configured !== 'boolean') throw new Error('Invalid DNS settings response');
      setMetadata({
        configured: value.configured,
        dns_provider: typeof value.dns_provider === 'string' ? value.dns_provider : '',
        email: typeof value.email === 'string' ? value.email : '',
      });
    }).catch(() => { if (active) setFailed(true); });
    return () => { active = false; abort.abort(); };
  }, [form, path]);

  React.useEffect(() => {
    if (mode !== 'dns') {
      if (usesGlobal === true) {
        useGlobal(form, path, false);
        syncDnsEnvText(form, path, onDnsEnvTextChange);
      }
      return;
    }
    if (!domain?.trim()) {
      const suggested = certificateDomain(sniSettings, sniObject, sniLegacy, host);
      if (suggested) form.setValue(`${path}.domain`, suggested, fieldOptions);
    }
    // Missing flags on an existing node preserve its old independent configuration.
    if (usesGlobal !== undefined) return;
    if (nodeId !== undefined && nodeId !== null && nodeId !== '') {
      form.setValue(`${path}.use_global_dns`, false, fieldOptions);
      return;
    }
    if (!metadata) return;
    const current = form.getValues(path) || {};
    const hasIndependent = !!current.dns_provider || Object.keys(current.dns_env || {}).length > 0 || !!current.email;
    useGlobal(form, path, metadata.configured && !hasIndependent);
  }, [form, path, mode, metadata, nodeId, usesGlobal, domain, sniSettings, sniObject, sniLegacy, host, onDnsEnvTextChange]);

  const usingGlobal = mode === 'dns' && usesGlobal === true;
  const toggle = event => {
    useGlobal(form, path, event.target.checked);
    syncDnsEnvText(form, path, onDnsEnvTextChange);
  };
  const settingsPath = `/${encodeURIComponent(window.settings?.secure_path || '')}/dns-settings`;
  const panel = mode !== 'dns' ? null : h('section', { className: 'xb-node-dns-panel', 'data-node-dns': 'defaults' },
    h('label', { className: 'xb-node-dns-choice' },
      h('input', {
        type: 'checkbox', checked: usingGlobal, onChange: toggle,
        disabled: !usingGlobal && (!metadata?.configured || failed),
        'aria-label': '使用全局 DNS 配置',
      }),
      h('span', null, '使用全局 DNS 配置')),
    failed ? h('p', { role: 'status' }, '无法读取全局 DNS 配置，请重新打开此窗口，或使用本节点独立配置。')
      : !metadata ? h('p', { role: 'status' }, '正在读取全局 DNS 配置…')
        : metadata.configured ? (usingGlobal
          ? h(React.Fragment, null,
            h('dl', { className: 'xb-node-dns-summary' },
              h('dt', null, 'DNS 提供商'), h('dd', null, metadata.dns_provider || '—'),
              h('dt', null, '通知邮箱'), h('dd', null, metadata.email || '未设置')),
            h('p', null, '使用全局保存的凭据。这里只设置本节点证书域名，密钥不会回填或复制到节点。'))
          : h('p', null, '切换为独立配置后，需要填写该节点的 DNS 提供商、邮箱和凭据。勾选后使用全局保存的配置。'))
          : h('p', { role: 'status' }, usingGlobal
            ? '该节点引用的全局 DNS 配置当前不可用，请先配置，或切换到独立配置。'
            : '尚未配置全局 DNS，可先保存一份配置，以后添加节点直接复用。'),
    h('a', { href: settingsPath, target: '_blank', rel: 'noopener noreferrer' }, '管理全局 DNS 配置 ↗'));
  return { panel, usingGlobal };
}
