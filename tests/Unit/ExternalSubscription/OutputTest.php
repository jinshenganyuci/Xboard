<?php

namespace Tests\Unit\ExternalSubscription;

use App\Models\SubscribeTemplate;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Protocols\Surge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OutputTest extends TestCase
{
    use RefreshDatabase;

    private function user(): array
    {
        return ['uuid' => 'local-user-must-not-replace-upstream', 'token' => 'test-subscription-token', 'u' => 100, 'd' => 200,
            'transfer_enable' => 0, 'expired_at' => time() + 3600];
    }

    private function nodes(): array
    {
        $tls = ['server_name' => 'tls.example.com', 'allow_insecure' => false, 'alpn' => ['h2', 'http/1.1']];
        return [
            ['id' => -1, 'name' => 'VLESS test', 'type' => 'vless', 'host' => '2001:db8::1', 'port' => 443,
                'password' => '11111111-2222-4333-8444-555555555555', 'external' => true, 'tags' => [],
                'protocol_settings' => ['network' => 'ws', 'network_settings' => ['path' => '/ws?test=1', 'headers' => ['Host' => 'ws.example.com']],
                    'tls' => 1, 'tls_settings' => $tls, 'utls' => ['enabled' => true, 'fingerprint' => 'chrome']]],
            ['id' => -2, 'name' => 'Trojan # / test', 'type' => 'trojan', 'host' => 'trojan.example.com', 'port' => 443,
                'password' => 'p@ss:word/#?+ %', 'external' => true, 'tags' => [],
                'protocol_settings' => ['network' => 'grpc', 'network_settings' => ['serviceName' => 'svc/path'],
                    'tls' => 1, 'tls_settings' => $tls, 'utls' => ['enabled' => false]]],
            ['id' => -3, 'name' => 'Hy2 # / test', 'type' => 'hysteria', 'host' => 'hy2.example.com', 'port' => 8443,
                'password' => 'hy@pass/#?+ %', 'external' => true, 'tags' => [],
                'protocol_settings' => ['version' => 2, 'tls' => ['server_name' => 'hy2-tls.example.com', 'allow_insecure' => false, 'alpn' => ['h3']],
                    'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'obfs-password']]],
        ];
    }

    public function test_general_and_shadowrocket_keep_credentials_encoded_names_and_tls_parameters(): void
    {
        foreach ([General::class, Shadowrocket::class] as $class) {
            $body = base64_decode((new $class($this->user(), $this->nodes()))->handle()->getContent());
            $this->assertStringNotContainsString($this->user()['uuid'], $body);
            $this->assertStringNotContainsString('TOT:', $body);
            foreach (array_slice($this->nodes(), 1) as $node) {
                $scheme = $node['type'] === 'hysteria' ? 'hysteria2' : 'trojan';
                $line = collect(explode("\n", $body))->first(fn ($line) => str_starts_with($line, $scheme . '://'));
                $this->assertNotNull($line);
                $parsed = parse_url(trim($line));
                $this->assertSame($node['password'], rawurldecode($parsed['user']));
                $this->assertSame($node['name'], rawurldecode($parsed['fragment']));
                parse_str($parsed['query'], $params);
                $this->assertArrayHasKey('alpn', $params);
                if ($scheme === 'hysteria2') {
                    $this->assertSame('salamander', $params['obfs']);
                    $this->assertSame('obfs-password', $params['obfs-password']);
                }
            }
            $vless = collect(explode("\n", $body))->first(fn ($line) => str_starts_with($line, 'vless://'));
            if ($class === General::class) {
                $this->assertStringContainsString($this->nodes()[0]['password'], $vless);
                $this->assertStringContainsString('[2001:db8::1]', $vless);
            } else {
                $userinfo = explode('?', substr($vless, strlen('vless://')), 2)[0];
                $this->assertStringContainsString($this->nodes()[0]['password'], base64_decode($userinfo));
            }
        }
    }

    public function test_clash_meta_and_sing_box_preserve_upstream_authentication_and_transport_settings(): void
    {
        $nodes = $this->nodes();
        $yaml = (new ClashMeta($this->user(), $nodes))->handle();
        $clash = collect(Yaml::parse($yaml->getContent())['proxies'])->keyBy('name');
        $json = (new SingBox($this->user(), $nodes, 'sing-box', '1.12.0'))->handle();
        $sing = collect(json_decode($json->getContent(), true)['outbounds'])->keyBy('tag');
        foreach ($nodes as $node) {
            $credential = $node['type'] === 'vless' ? 'uuid' : 'password';
            $this->assertSame($node['password'], $clash[$node['name']][$credential]);
            $this->assertSame($node['password'], $sing[$node['name']][$credential]);
        }
        $this->assertSame('/ws?test=1', $clash['VLESS test']['ws-opts']['path']);
        $this->assertSame('ws.example.com', $sing['VLESS test']['transport']['headers']['Host']);
        $this->assertSame('svc/path', $sing['Trojan # / test']['transport']['service_name']);
        $this->assertSame(['h2', 'http/1.1'], $clash['Trojan # / test']['alpn']);
        $this->assertSame(['h3'], $sing['Hy2 # / test']['tls']['alpn']);
        $this->assertSame('obfs-password', $sing['Hy2 # / test']['obfs']['password']);
        foreach ([$yaml, $json] as $response) {
            $this->assertStringNotContainsString('total=', $response->headers->get('subscription-userinfo', ''));
            $this->assertStringNotContainsString($this->user()['uuid'], $response->getContent());
        }
    }

    public function test_reality_values_survive_all_supported_formats(): void
    {
        $node = $this->nodes()[0];
        $node['protocol_settings'] = [
            'network' => 'tcp', 'tls' => 2, 'flow' => 'xtls-rprx-vision',
            'reality_settings' => ['server_name' => 'reality.example.com', 'public_key' => 'public-test-key', 'short_id' => 'abcd'],
            'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
        ];
        foreach ([General::class, Shadowrocket::class] as $class) {
            $body = base64_decode((new $class($this->user(), [$node]))->handle()->getContent());
            $this->assertStringContainsString('public-test-key', $body);
            $this->assertStringContainsString('abcd', $body);
            $this->assertStringContainsString('reality.example.com', $body);
        }
        $clash = ClashMeta::buildVless($node['password'], $node);
        $this->assertSame('public-test-key', $clash['reality-opts']['public-key']);
        $sing = json_decode((new SingBox($this->user(), [$node]))->handle()->getContent(), true);
        $outbound = collect($sing['outbounds'])->firstWhere('tag', $node['name']);
        $this->assertSame('public-test-key', $outbound['tls']['reality']['public_key']);
    }

    public function test_clash_external_names_avoid_template_groups_proxies_and_builtins(): void
    {
        $original = subscribe_template('clashmeta');
        $appName = admin_setting('app_name', 'XBoard');
        $template = [
            'proxies' => [ClashMeta::buildTrojan('template-password', array_replace($this->nodes()[1], ['name' => 'Template proxy']))],
            'proxy-groups' => [
                ['name' => '$app_name', 'type' => 'select', 'proxies' => ['DIRECT', 'Template proxy']],
                ['name' => '自动选择', 'type' => 'url-test', 'proxies' => [], 'url' => 'https://example.com', 'interval' => 300],
            ],
            'rules' => ['MATCH,$app_name'],
        ];
        $names = ['自动选择', '自动选择 #2', '$app_name', $appName . ' #2', 'DIRECT',
            'Template proxy', 'Shared name', 'Shared name', 'Native name', 'Normal 🇭🇰'];
        $nodes = array_map(fn ($name) => array_replace($this->nodes()[1], ['name' => $name]), $names);
        $nodes[] = array_replace($this->nodes()[1], ['name' => 'Native name', 'external' => false]);
        $expected = ['自动选择 #3', '自动选择 #2', $appName . ' #3', $appName . ' #2', 'DIRECT #2',
            'Template proxy #2', 'Shared name', 'Shared name #2', 'Native name #2', 'Normal 🇭🇰', 'Native name'];

        try {
            SubscribeTemplate::setContent('clashmeta', Yaml::dump($template));
            $config = Yaml::parse((new ClashMeta($this->user(), $nodes))->handle()->getContent());
            $this->assertSame(array_merge(['Template proxy'], $expected), array_column($config['proxies'], 'name'));
            $allNames = array_merge(array_column($config['proxies'], 'name'), array_column($config['proxy-groups'], 'name'));
            $this->assertSame(count($allNames), count(array_unique($allNames)));
            $this->assertSame(array_merge(['DIRECT', 'Template proxy'], $expected), $config['proxy-groups'][0]['proxies']);
            $this->assertSame($expected, $config['proxy-groups'][1]['proxies']);
            $this->assertSame('MATCH,' . $appName, $config['rules'][array_key_last($config['rules'])]);
        } finally {
            SubscribeTemplate::setContent('clashmeta', $original);
        }
    }

    public function test_sing_box_external_names_avoid_template_tags_and_keep_group_references(): void
    {
        $original = subscribe_template('singbox');
        $template = [
            'outbounds' => [
                ['tag' => '节点选择', 'type' => 'selector', 'outbounds' => ['direct']],
                ['tag' => 'direct', 'type' => 'direct'],
                ['tag' => 'Template outbound', 'type' => 'direct'],
                ['tag' => '自动选择', 'type' => 'urltest', 'outbounds' => []],
            ],
            'route' => ['rules' => []],
        ];
        $names = ['节点选择', '节点选择 #2', 'direct', 'Template outbound',
            'Shared name', 'Shared name', 'Native name', 'Normal 🇭🇰'];
        $nodes = array_map(fn ($name) => array_replace($this->nodes()[1], ['name' => $name]), $names);
        $nodes[] = array_replace($this->nodes()[1], ['name' => 'Native name', 'external' => false]);
        $expected = ['节点选择 #3', '节点选择 #2', 'direct #2', 'Template outbound #2',
            'Shared name', 'Shared name #2', 'Native name #2', 'Normal 🇭🇰', 'Native name'];

        try {
            SubscribeTemplate::setContent('singbox', json_encode($template));
            $config = json_decode((new SingBox($this->user(), $nodes, 'sing-box', '1.12.0'))->handle()->getContent(), true);
            $tags = array_column($config['outbounds'], 'tag');
            $this->assertSame(array_merge(array_column($template['outbounds'], 'tag'), $expected), $tags);
            $this->assertSame(count($tags), count(array_unique($tags)));
            $this->assertSame(array_merge(['direct'], $expected), $config['outbounds'][0]['outbounds']);
            $this->assertSame($expected, $config['outbounds'][3]['outbounds']);
        } finally {
            SubscribeTemplate::setContent('singbox', $original);
        }
    }

    public function test_hysteria_hopping_ports_survive_all_formats_and_unversioned_sing_box_clients(): void
    {
        $node = $this->nodes()[2];
        $node['ports'] = '8443,20000-21000,25000-26000';
        $node['protocol_settings']['hop_interval'] = 30;
        foreach ([General::class, Shadowrocket::class] as $class) {
            $body = base64_decode((new $class($this->user(), [$node]))->handle()->getContent());
            $line = collect(explode("\n", $body))->first(fn ($line) => str_starts_with($line, 'hysteria2://'));
            parse_str(parse_url(trim($line), PHP_URL_QUERY), $params);
            $this->assertSame($node['ports'], $params['mport']);
            $this->assertSame('30', $params['hop-interval']);
            $this->assertArrayNotHasKey('keepalive', $params);
        }
        $clash = ClashMeta::buildHysteria($node['password'], $node, $this->user());
        $this->assertSame($node['ports'], $clash['ports']);
        $this->assertSame(30, $clash['hop-interval']);

        foreach ([[null, null], ['sing-box', null], ['sing-box', '1.11.0'], ['hiddify', null], ['hiddify', '2.0.0']] as [$client, $version]) {
            $json = (new SingBox($this->user(), [$node], $client, $version))->handle()->getContent();
            $outbound = collect(json_decode($json, true)['outbounds'])->firstWhere('tag', $node['name']);
            $this->assertSame(['8443:8443', '20000:21000', '25000:26000'], $outbound['server_ports']);
            $this->assertArrayNotHasKey('server_port', $outbound);
            $this->assertSame('30s', $outbound['hop_interval']);
            $this->assertSame($node['password'], $outbound['password']);
        }

        $json = (new SingBox($this->user(), [$node], 'sing-box', '1.10.7'))->handle()->getContent();
        $this->assertNull(collect(json_decode($json, true)['outbounds'])->firstWhere('tag', $node['name']));
    }

    public function test_unverified_client_formats_do_not_receive_external_credentials(): void
    {
        $body = (new Surge($this->user(), $this->nodes()))->handle()->getContent();
        foreach ($this->nodes() as $node) {
            $this->assertStringNotContainsString($node['password'], $body);
        }
    }

    public function test_sing_box_modern_and_unversioned_clients_migrate_the_default_template(): void
    {
        $template = json_decode(file_get_contents(base_path(SingBox::DEFAULT_TEMPLATE_FILE)), true);
        $modern = $this->adaptSingBoxTemplate($template, '1.13.0');
        $this->assertSame($modern, $this->adaptSingBoxTemplate($template, '1.14.3'));
        $this->assertSame($modern, $this->adaptSingBoxTemplate($template, null));
        $servers = collect($modern['dns']['servers'])->keyBy('tag');
        $this->assertSame('https', $servers['remote']['type']);
        $this->assertSame('1.1.1.1', $servers['remote']['server']);
        $this->assertSame('/dns-query', $servers['remote']['path']);
        $this->assertSame('节点选择', $servers['remote']['detour']);
        $this->assertSame('direct', $servers['local']['detour']);
        $this->assertArrayNotHasKey('block', $servers->all());
        $this->assertSame(['server' => 'local'], $modern['route']['default_domain_resolver']);
        $this->assertFalse(collect($modern['dns']['rules'])->contains(fn ($rule) => isset($rule['outbound'])));
        $this->assertFalse(collect($modern['outbounds'])->contains(fn ($outbound) => in_array($outbound['type'], ['dns', 'block'])));
        foreach ($modern['inbounds'] as $inbound) {
            foreach (['sniff', 'sniff_override_destination', 'domain_strategy', 'endpoint_independent_nat'] as $field) {
                $this->assertArrayNotHasKey($field, $inbound);
            }
            $rules = collect($modern['route']['rules'])->filter(fn ($rule) => ($rule['inbound'] ?? null) === [$inbound['tag']]);
            $this->assertSame(['resolve', 'sniff'], $rules->pluck('action')->values()->all());
        }
        $old = $this->adaptSingBoxTemplate($template, '1.12.0');
        $this->assertSame($template, $old);
    }

    public function test_sing_box_dns_migration_preserves_custom_resolver_and_response_semantics(): void
    {
        $template = ['dns' => ['servers' => [
            ['tag' => 'custom', 'address' => 'https://dns.example.com:8443/custom-query?test=1',
                'address_resolver' => 'bootstrap', 'address_strategy' => 'prefer_ipv4',
                'strategy' => 'ipv6_only', 'client_subnet' => '192.0.2.0/24', 'detour' => 'direct'],
            ['tag' => 'bootstrap', 'address' => '1.1.1.1'],
            ['tag' => 'empty', 'address' => 'rcode://success'],
        ], 'rules' => [
            ['domain' => ['allowed.example'], 'server' => 'custom'],
            ['domain' => ['empty.example'], 'server' => 'empty'],
        ], 'final' => 'empty'], 'outbounds' => [], 'route' => ['rules' => []]];
        $modern = $this->adaptSingBoxTemplate($template, '1.14.3');
        $dns = $modern['dns']['servers'][0];
        $this->assertSame('dns.example.com', $dns['server']);
        $this->assertSame(8443, $dns['server_port']);
        $this->assertSame('/custom-query?test=1', $dns['path']);
        $this->assertSame(['server' => 'bootstrap', 'strategy' => 'prefer_ipv4'], $dns['domain_resolver']);
        $this->assertArrayNotHasKey('strategy', $dns);
        $this->assertSame('ipv6_only', $modern['dns']['rules'][0]['strategy']);
        $this->assertSame('192.0.2.0/24', $modern['dns']['rules'][0]['client_subnet']);
        $this->assertSame('predefined', $modern['dns']['rules'][1]['action']);
        $this->assertSame('NOERROR', $modern['dns']['rules'][1]['rcode']);
        $this->assertSame(['action' => 'predefined', 'rcode' => 'NOERROR'], $modern['dns']['rules'][2]);
        $this->assertArrayNotHasKey('final', $modern['dns']);
    }

    private function adaptSingBoxTemplate(array $template, ?string $version): array
    {
        $protocol = new SingBox($this->user(), $this->nodes(), 'sing-box', $version);
        $reflection = new \ReflectionClass($protocol);
        $reflection->getProperty('config')->setValue($protocol, $template);
        $reflection->getMethod('adaptConfigForVersion')->invoke($protocol);
        return $reflection->getProperty('config')->getValue($protocol);
    }
}
