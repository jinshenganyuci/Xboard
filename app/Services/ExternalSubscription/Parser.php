<?php

namespace App\Services\ExternalSubscription;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class Parser
{
    public const MAX_BYTES = 2097152;
    public const MAX_NODES = 1000;

    public function parse(string $body): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('订阅内容超过 2 MiB 限制。');
        }
        $body = trim(preg_replace('/^\xEF\xBB\xBF/', '', $body));
        if ($body === '') {
            throw new RuntimeException('订阅为空，已保留上次同步结果。');
        }
        if (!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $body) && preg_match('/^[A-Za-z0-9+\/_=\s-]+$/D', $body)) {
            $encoded = preg_replace('/\s+/', '', $body);
            $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
            if ($decoded === false || strlen($decoded) > self::MAX_BYTES) {
                throw new RuntimeException('订阅 Base64 格式无效。');
            }
            $body = trim($decoded);
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $body)) {
            $entries = preg_split('/\R/u', $body);
            $entries = array_values(array_filter(array_map('trim', $entries), fn ($line) => $line !== ''));
            $format = 'uri';
        } else {
            try {
                // Anchors are unnecessary for node imports and can amplify untrusted YAML.
                if (preg_match('/(?:^|\s)[&*][A-Za-z0-9_-]+/m', $body)) {
                    throw new RuntimeException();
                }
                $yaml = Yaml::parse($body, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (Throwable $e) {
                throw new RuntimeException('订阅 YAML 格式无效或包含不支持的引用。');
            }
            if (!is_array($yaml) || !isset($yaml['proxies']) || !is_array($yaml['proxies'])) {
                throw new RuntimeException('未找到 URI 列表或 Clash proxies 节点列表。');
            }
            $entries = array_values($yaml['proxies']);
            $format = 'yaml';
        }
        if (!$entries || count($entries) > self::MAX_NODES) {
            throw new RuntimeException('节点列表为空或超过 1000 个节点限制。');
        }
        $nodes = [];
        foreach ($entries as $index => $entry) {
            $name = $this->displayName($entry, $index);
            $type = is_array($entry) ? ($entry['type'] ?? 'unknown') : (strstr($entry, ':', true) ?: 'unknown');
            $type = in_array($type, ['vless', 'trojan', 'hysteria2', 'hy2', 'hysteria', 'ss', 'ssr', 'vmess'], true) ? $type : 'unknown';
            try {
                $config = $format === 'uri' ? $this->fromUri($entry, $name) : $this->fromClash($entry, $name);
                $fingerprint = hash('sha256', json_encode([$config['type'], strtolower($config['host']), $config['port'], $name], JSON_UNESCAPED_UNICODE));
                $node = ['fingerprint' => $fingerprint, 'name' => $name, 'type' => $config['type'], 'config' => $config, 'supported' => true, 'error' => null];
            } catch (RuntimeException $e) {
                $node = [
                    'fingerprint' => hash('sha256', 'unsupported:' . json_encode($entry)),
                    'name' => $name, 'type' => $type, 'config' => [], 'supported' => false,
                    'error' => $e->getMessage(),
                ];
            } catch (Throwable $e) {
                $node = [
                    'fingerprint' => hash('sha256', 'invalid:' . json_encode($entry)),
                    'name' => $name, 'type' => $type, 'config' => [], 'supported' => false,
                    'error' => '节点格式无效。',
                ];
            }
            if (isset($nodes[$node['fingerprint']])) {
                if ($nodes[$node['fingerprint']]['config'] !== $node['config']) {
                    $nodes[$node['fingerprint']]['supported'] = false;
                    $nodes[$node['fingerprint']]['error'] = '同名同地址节点存在不同配置，无法稳定匹配，请在上游区分名称。';
                }
                continue;
            }
            $nodes[$node['fingerprint']] = $node;
        }
        return array_values($nodes);
    }

    private function displayName(mixed $entry, int $index): string
    {
        $name = is_array($entry) ? ($entry['name'] ?? null) : null;
        if (is_string($entry) && str_contains($entry, '#')) {
            $name = rawurldecode(substr($entry, strpos($entry, '#') + 1));
        }
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            $name = '';
        }
        $name = trim(preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name));
        return mb_substr($name !== '' ? $name : '未命名节点 ' . ($index + 1), 0, 200);
    }

    private function fromUri(string $uri, string $name): array
    {
        if (strlen($uri) > 16384 || preg_match('/[\x00-\x20\x7f]/', $uri)) {
            throw new RuntimeException('节点 URI 包含非法字符或过长。');
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['user'])) {
            throw new RuntimeException('节点 URI 缺少地址或认证信息。');
        }
        if (isset($parts['pass']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('节点 URI 认证格式或路径不受支持。');
        }
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode($key);
            if (array_key_exists($key, $query)) {
                throw new RuntimeException('节点包含重复参数。');
            }
            $query[$key] = rawurldecode($value);
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? (in_array($scheme, ['hy2', 'hysteria2'], true) ? 443 : null);
        return $this->build($scheme, trim($parts['host'], '[]'), $port, rawurldecode($parts['user']), $name, $query);
    }

    private function fromClash(mixed $node, string $name): array
    {
        if (!is_array($node)) {
            throw new RuntimeException('Clash 节点必须是对象。');
        }
        $this->onlyKeys($node, ['name', 'type', 'server', 'port', 'ports', 'hop-interval', 'uuid', 'password', 'network', 'tls', 'servername', 'sni', 'skip-cert-verify', 'client-fingerprint', 'alpn', 'flow', 'reality-opts', 'ws-opts', 'grpc-opts', 'h2-opts', 'udp', 'tfo', 'obfs', 'obfs-password', 'up', 'down']);
        if ((isset($node['udp']) && $node['udp'] !== true) || !empty($node['tfo'])) {
            throw new RuntimeException('节点包含当前无法保真的 UDP/TFO 设置。');
        }
        $type = $node['type'] ?? '';
        if (($type === 'vless' && isset($node['password'])) || ($type !== 'vless' && isset($node['uuid']))) {
            throw new RuntimeException('节点包含冲突的认证字段。');
        }
        $transportOptions = array_intersect(['ws-opts', 'grpc-opts', 'h2-opts'], array_keys($node));
        if (count($transportOptions) > 1) {
            throw new RuntimeException('节点包含多个传输配置。');
        }
        foreach (['ws-opts' => ['ws', 'httpupgrade'], 'grpc-opts' => ['grpc'], 'h2-opts' => ['h2']] as $key => $networks) {
            if (isset($node[$key], $node['network']) && !in_array($node['network'], $networks, true)) {
                throw new RuntimeException('节点传输配置与网络类型不一致。');
            }
        }
        $q = [];
        foreach (['sni' => 'sni', 'servername' => 'servername', 'flow' => 'flow', 'client-fingerprint' => 'fp', 'obfs' => 'obfs', 'obfs-password' => 'obfs-password', 'up' => 'upmbps', 'down' => 'downmbps', 'ports' => 'mport', 'hop-interval' => 'hop-interval'] as $key => $target) {
            if (isset($node[$key])) {
                $q[$target] = $node[$key];
            }
        }
        if (isset($node['skip-cert-verify'])) {
            $q['allowInsecure'] = $node['skip-cert-verify'];
        }
        if (isset($node['alpn'])) {
            if (!is_array($node['alpn'])) {
                throw new RuntimeException('ALPN 必须是列表。');
            }
            $q['alpn'] = implode(',', $node['alpn']);
        }
        if (isset($node['reality-opts'])) {
            $this->onlyKeys($node['reality-opts'], ['public-key', 'short-id']);
            $q['security'] = 'reality';
            $q['pbk'] = $node['reality-opts']['public-key'] ?? '';
            $q['sid'] = $node['reality-opts']['short-id'] ?? '';
        } elseif (isset($node['tls'])) {
            if (!is_bool($node['tls'])) {
                throw new RuntimeException('TLS 设置必须是布尔值。');
            }
            $q['security'] = $node['tls'] ? 'tls' : 'none';
        }
        if (isset($node['network'])) {
            $q['type'] = $node['network'];
        }
        if (isset($node['ws-opts'])) {
            $this->onlyKeys($node['ws-opts'], ['path', 'headers', 'v2ray-http-upgrade']);
            $this->onlyKeys($node['ws-opts']['headers'] ?? [], ['Host']);
            $q['path'] = $node['ws-opts']['path'] ?? '/';
            $q['host'] = $node['ws-opts']['headers']['Host'] ?? '';
            $q['type'] = !empty($node['ws-opts']['v2ray-http-upgrade']) ? 'httpupgrade' : 'ws';
        }
        if (isset($node['grpc-opts'])) {
            $this->onlyKeys($node['grpc-opts'], ['grpc-service-name']);
            $q['type'] = 'grpc';
            $q['serviceName'] = $node['grpc-opts']['grpc-service-name'] ?? '';
        }
        if (isset($node['h2-opts'])) {
            $this->onlyKeys($node['h2-opts'], ['path', 'host']);
            $q['type'] = 'h2';
            $q['path'] = $node['h2-opts']['path'] ?? '/';
            $host = $node['h2-opts']['host'] ?? [];
            if (is_array($host) && count($host) > 1) {
                throw new RuntimeException('暂不支持多个 HTTP/2 Host。');
            }
            $q['host'] = is_array($host) ? ($host[0] ?? '') : $host;
        }
        return $this->build($type, $node['server'] ?? '', $node['port'] ?? null, $node[$type === 'vless' ? 'uuid' : 'password'] ?? '', $name, $q);
    }

    private function build(string $type, string $host, mixed $port, string $credential, string $name, array $q): array
    {
        if (!in_array($type, ['vless', 'trojan', 'hysteria2', 'hy2'], true)) {
            throw new RuntimeException('首版仅支持 VLESS、Trojan 和 Hysteria2。');
        }
        // Common URI generators emit empty placeholders for transports they do not use.
        // Only known, semantically empty defaults are removed; real pinning/QUIC settings fail closed.
        foreach (['pcs', 'path', 'host', 'serviceName'] as $placeholder) {
            if (array_key_exists($placeholder, $q) && $q[$placeholder] === '') {
                unset($q[$placeholder]);
            }
        }
        if (isset($q['quicSecurity']) && in_array($q['quicSecurity'], ['', 'none'], true) && ($q['type'] ?? 'tcp') !== 'quic') {
            unset($q['quicSecurity']);
        }
        $this->onlyKeys($q, ['type', 'security', 'sni', 'servername', 'peer', 'allowInsecure', 'insecure', 'alpn', 'fp', 'pbk', 'sid', 'flow', 'encryption', 'path', 'host', 'serviceName', 'mode', 'headerType', 'obfs', 'obfs-password', 'upmbps', 'downmbps', 'mport', 'hop-interval']);
        foreach ($q as $value) {
            if (!is_scalar($value) || (is_string($value) && (strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)))) {
                throw new RuntimeException('节点参数格式无效。');
            }
        }
        if ((!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) || strlen($host) > 253 || !filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
            throw new RuntimeException('节点地址或端口无效。');
        }
        if ($credential === '' || strlen($credential) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $credential)) {
            throw new RuntimeException('节点认证信息无效。');
        }
        if ($type === 'vless' && !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $credential)) {
            throw new RuntimeException('VLESS UUID 格式无效。');
        }
        $sniValues = array_values(array_unique(array_filter([$q['sni'] ?? '', $q['servername'] ?? '', $q['peer'] ?? ''], fn ($v) => $v !== '')));
        if (count($sniValues) > 1) {
            throw new RuntimeException('节点包含冲突的 SNI 参数。');
        }
        $sni = $sniValues[0] ?? null;
        $insecure = $this->boolean($q['allowInsecure'] ?? $q['insecure'] ?? false);
        if (isset($q['allowInsecure'], $q['insecure']) && $this->boolean($q['allowInsecure']) !== $this->boolean($q['insecure'])) {
            throw new RuntimeException('节点包含冲突的证书校验参数。');
        }
        $alpn = isset($q['alpn']) && $q['alpn'] !== '' ? explode(',', $q['alpn']) : [];
        foreach ($alpn as $item) {
            if (!preg_match('/^[a-zA-Z0-9.\/-]{1,64}$/D', $item)) {
                throw new RuntimeException('ALPN 设置无效。');
            }
        }
        $tls = ['server_name' => $sni, 'allow_insecure' => $insecure];
        if ($alpn) {
            $tls['alpn'] = $alpn;
        }
        $settings = [];
        $ports = null;
        if (in_array($type, ['hysteria2', 'hy2'], true)) {
            $this->onlyKeys($q, ['sni', 'servername', 'peer', 'allowInsecure', 'insecure', 'alpn', 'obfs', 'obfs-password', 'upmbps', 'downmbps', 'mport', 'hop-interval']);
            $type = 'hysteria';
            $settings = ['version' => 2, 'tls' => $tls];
            $obfs = $q['obfs'] ?? '';
            if ($obfs === 'none') {
                $obfs = '';
            }
            if (!in_array($obfs, ['', 'salamander'], true) || ($obfs === 'salamander' && (!isset($q['obfs-password']) || (string) $q['obfs-password'] === '')) || ($obfs === '' && isset($q['obfs-password']))) {
                throw new RuntimeException('Hysteria2 混淆参数不受支持或不完整。');
            }
            $settings['obfs'] = ['open' => $obfs !== '', 'type' => 'salamander', 'password' => $q['obfs-password'] ?? null];
            if (isset($q['mport'])) {
                $ports = $this->ports((string) $q['mport']);
            }
            if (isset($q['hop-interval'])) {
                $interval = filter_var($q['hop-interval'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
                if ($interval === false) {
                    throw new RuntimeException('跳跃端口间隔必须为 1 至 3600 秒。');
                }
                $settings['hop_interval'] = $interval;
            }
            foreach (['upmbps' => 'up', 'downmbps' => 'down'] as $key => $target) {
                if (isset($q[$key])) {
                    if (!is_numeric($q[$key]) || (float) $q[$key] <= 0) {
                        throw new RuntimeException('Hysteria2 带宽设置必须为正数 Mbps。');
                    }
                    $settings['bandwidth'][$target] = (float) $q[$key];
                }
            }
        } else {
            $this->onlyKeys($q, ['type', 'security', 'sni', 'servername', 'peer', 'allowInsecure', 'insecure', 'alpn', 'fp', 'pbk', 'sid', 'flow', 'encryption', 'path', 'host', 'serviceName', 'mode', 'headerType']);
            $security = $q['security'] ?? ($type === 'trojan' ? 'tls' : 'none');
            if ($type === 'trojan' && $security === 'none') {
                throw new RuntimeException('Trojan 暂不支持关闭 TLS。');
            }
            if (!in_array($security, ['none', 'tls', 'reality'], true) || ($q['encryption'] ?? 'none') !== 'none' || !in_array($q['headerType'] ?? 'none', ['', 'none'], true)) {
                throw new RuntimeException('节点的加密或 TCP 伪装参数不受支持。');
            }
            $network = $q['type'] ?? 'tcp';
            if (!in_array($network, ['tcp', 'ws', 'grpc', 'h2', 'httpupgrade'], true)) {
                throw new RuntimeException('节点传输方式不受支持。');
            }
            if ($type === 'trojan' && $network === 'h2') {
                throw new RuntimeException('首版暂不支持 Trojan HTTP/2 传输。');
            }
            if (isset($q['mode']) && ($network !== 'grpc' || $q['mode'] !== 'gun')) {
                throw new RuntimeException('暂不支持该 gRPC 模式。');
            }
            $flow = $q['flow'] ?? '';
            if (!in_array($flow, ['', 'xtls-rprx-vision'], true) || ($flow !== '' && ($type !== 'vless' || $network !== 'tcp' || $security === 'none'))) {
                throw new RuntimeException('节点 Flow 参数不受支持。');
            }
            $settings = ['tls' => array_search($security, ['none', 'tls', 'reality'], true), 'network' => $network, 'tls_settings' => $tls, 'flow' => $flow, 'utls' => ['enabled' => $security === 'reality' || !empty($q['fp']), 'fingerprint' => !empty($q['fp']) ? $q['fp'] : 'chrome']];
            if ($security === 'reality') {
                if (empty($q['pbk']) || !preg_match('/^[a-zA-Z0-9_-]{43}=?$/D', $q['pbk']) || !preg_match('/^(?:[a-fA-F0-9]{2}){0,8}$/D', $q['sid'] ?? '')) {
                    throw new RuntimeException('Reality 公钥或 Short ID 无效。');
                }
                $settings['reality_settings'] = ['server_name' => $sni, 'public_key' => $q['pbk'], 'short_id' => $q['sid'] ?? '', 'allow_insecure' => $insecure];
            } elseif (isset($q['pbk']) || isset($q['sid'])) {
                throw new RuntimeException('Reality 参数与 TLS 模式不一致。');
            }
            $transport = [];
            if (in_array($network, ['ws', 'httpupgrade', 'h2'], true)) {
                $transport['path'] = $q['path'] ?? '/';
                if (isset($q['host']) && $q['host'] !== '') {
                    if ($network === 'ws') {
                        $transport['headers'] = ['Host' => $q['host']];
                    } else {
                        $transport['host'] = $q['host'];
                    }
                }
                if (isset($q['serviceName'])) {
                    throw new RuntimeException('节点传输参数相互冲突。');
                }
            } elseif ($network === 'grpc') {
                $transport['serviceName'] = $q['serviceName'] ?? '';
                $transport['mode'] = 'gun';
                if (isset($q['path']) || isset($q['host'])) {
                    throw new RuntimeException('gRPC 的额外路径或 Host 尚不支持。');
                }
            } elseif (isset($q['path']) || isset($q['host']) || isset($q['serviceName'])) {
                throw new RuntimeException('TCP 的额外路径或 Host 尚不支持。');
            }
            $settings['network_settings'] = $transport;
        }
        $config = ['name' => $name, 'type' => $type, 'host' => $host, 'port' => (int) $port, 'password' => $credential, 'protocol_settings' => $settings, 'tags' => [], 'external' => true];
        if ($ports !== null) {
            $config['ports'] = $ports;
        }
        return $config;
    }

    private function ports(string $input): string
    {
        $parts = explode(',', $input);
        if (!$parts || count($parts) > 64) {
            throw new RuntimeException('跳跃端口列表无效或过长。');
        }
        $normalized = [];
        foreach ($parts as $part) {
            if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/D', trim($part), $matches)) {
                throw new RuntimeException('跳跃端口仅支持端口号或起止范围。');
            }
            $start = (int) $matches[1];
            $end = isset($matches[2]) ? (int) $matches[2] : $start;
            if ($start < 1 || $end > 65535 || $start > $end) {
                throw new RuntimeException('跳跃端口必须在 1 至 65535 内且范围有序。');
            }
            $normalized[] = $start === $end ? (string) $start : $start . '-' . $end;
        }
        return implode(',', array_unique($normalized));
    }

    private function onlyKeys(mixed $values, array $allowed): void
    {
        if (!is_array($values) || array_diff(array_keys($values), $allowed)) {
            throw new RuntimeException('节点包含当前无法保真转换的参数。');
        }
    }

    private function boolean(mixed $value): bool
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
            throw new RuntimeException('布尔参数格式无效。');
        }
        return in_array($value, [true, 1, '1', 'true'], true);
    }
}
