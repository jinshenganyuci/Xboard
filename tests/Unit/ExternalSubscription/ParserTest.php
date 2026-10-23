<?php

namespace Tests\Unit\ExternalSubscription;

use App\Services\ExternalSubscription\Parser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ParserTest extends TestCase
{
    private const UUID = '11111111-2222-3333-4444-555555555555';

    public function test_base64_ipv6_vless_preserves_uuid_reality_and_encoded_name(): void
    {
        $uri = 'vless://' . self::UUID . '@[2606:4700:4700::1111]:443?security=reality&type=tcp&sni=edge.example.com&pbk=' . str_repeat('a', 43) . '&sid=aabb&fp=chrome&flow=xtls-rprx-vision#HK%20%2B%20%E9%A6%99%E6%B8%AF';
        $node = (new Parser())->parse(rtrim(strtr(base64_encode($uri), '+/', '-_'), '='))[0];
        $this->assertTrue($node['supported']);
        $this->assertSame('HK + 香港', $node['name']);
        $this->assertSame(self::UUID, $node['config']['password']);
        $this->assertSame('2606:4700:4700::1111', $node['config']['host']);
        $this->assertSame(2, $node['config']['protocol_settings']['tls']);
        $this->assertSame('aabb', $node['config']['protocol_settings']['reality_settings']['short_id']);
    }

    public function test_trojan_percent_encoded_password_ws_and_literal_plus(): void
    {
        $node = (new Parser())->parse('trojan://p%40ss%3A%2F%23%3F%2B@example.com:443?type=ws&path=%2Fweb%3Fed%3D1&host=cdn.example.com&sni=tls.example.com&alpn=h2%2Chttp%2F1.1#A+B')[0];
        $this->assertTrue($node['supported']);
        $this->assertSame('p@ss:/#?+', $node['config']['password']);
        $this->assertSame('A+B', $node['name']);
        $this->assertSame('/web?ed=1', $node['config']['protocol_settings']['network_settings']['path']);
        $this->assertSame(['h2', 'http/1.1'], $node['config']['protocol_settings']['tls_settings']['alpn']);
    }

    public function test_reality_defaults_to_enabled_chrome_utls_without_fp(): void
    {
        $node = (new Parser())->parse('vless://' . self::UUID . '@example.com:443?security=reality&pbk=' . str_repeat('a', 43) . '#Reality')[0];
        $this->assertTrue($node['supported']);
        $this->assertSame(['enabled' => true, 'fingerprint' => 'chrome'], $node['config']['protocol_settings']['utls']);
    }

    public function test_trojan_http2_is_explicitly_unsupported(): void
    {
        $node = (new Parser())->parse('trojan://pass@example.com:443?type=h2#H2')[0];
        $this->assertFalse($node['supported']);
    }

    public function test_hy2_defaults_port_and_preserves_obfs(): void
    {
        $node = (new Parser())->parse('hy2://pa%3Ass@example.com?sni=tls.example.com&obfs=salamander&obfs-password=ob%2Bfs&insecure=0#Hy2')[0];
        $this->assertTrue($node['supported']);
        $this->assertSame('hysteria', $node['type']);
        $this->assertSame(443, $node['config']['port']);
        $this->assertSame('pa:ss', $node['config']['password']);
        $this->assertSame('ob+fs', $node['config']['protocol_settings']['obfs']['password']);
        $this->assertFalse($node['config']['protocol_settings']['tls']['allow_insecure']);
    }

    public function test_common_empty_uri_placeholders_do_not_disable_valid_nodes(): void
    {
        $node = (new Parser())->parse('vless://' . self::UUID . '@example.com:443?type=tcp&encryption=none&host=&path=&serviceName=&headerType=none&quicSecurity=none&pcs=&security=tls&flow=xtls-rprx-vision#A')[0];
        $this->assertTrue($node['supported']);
        $this->assertSame('xtls-rprx-vision', $node['config']['protocol_settings']['flow']);
        $trojan = (new Parser())->parse('trojan://pass@example.com:443?type=tcp&pcs=#B')[0];
        $this->assertTrue($trojan['supported']);
        $pinned = (new Parser())->parse('trojan://pass@example.com:443?pcs=actual-pin#C')[0];
        $this->assertFalse($pinned['supported']);
    }

    public function test_hysteria_port_hopping_and_explicit_no_obfuscation(): void
    {
        $node = (new Parser())->parse('hy2://pass@example.com?mport=443%2C8000-9000&hop-interval=30&obfs=none#Hy2')[0];
        $this->assertTrue($node['supported']);
        $this->assertSame('443,8000-9000', $node['config']['ports']);
        $this->assertSame(30, $node['config']['protocol_settings']['hop_interval']);
        $this->assertFalse($node['config']['protocol_settings']['obfs']['open']);
        foreach (['0', '65536', '9000-8000', '443;80', '1-65536'] as $invalid) {
            $this->assertFalse((new Parser())->parse('hy2://pass@example.com?mport=' . rawurlencode($invalid) . '#Bad')[0]['supported']);
        }
    }

    public function test_clash_yaml_converts_grpc_and_keeps_credentials(): void
    {
        $yaml = "proxies:\n  - name: grpc node\n    type: vless\n    server: example.com\n    port: 443\n    uuid: " . self::UUID . "\n    tls: true\n    servername: tls.example.com\n    network: grpc\n    grpc-opts:\n      grpc-service-name: tunnel\n    client-fingerprint: chrome\n    alpn: [h2]\n";
        $node = (new Parser())->parse($yaml)[0];
        $this->assertTrue($node['supported']);
        $this->assertSame('tunnel', $node['config']['protocol_settings']['network_settings']['serviceName']);
        $this->assertSame(self::UUID, $node['config']['password']);
    }

    public function test_unknown_parameters_are_not_silently_dropped_or_exposed_in_errors(): void
    {
        $node = (new Parser())->parse('trojan://password@example.com:443?secret-key=secret-value#Node')[0];
        $this->assertFalse($node['supported']);
        $this->assertSame([], $node['config']);
        $this->assertStringNotContainsString('secret', $node['error']);
        $this->assertStringNotContainsString('password', $node['error']);
    }

    public function test_tls_false_trojan_and_unknown_protocol_are_unsupported(): void
    {
        $nodes = (new Parser())->parse("trojan://password@example.com:443?security=none#plain\nss://dGVzdA@example.com:443#old");
        $this->assertCount(2, $nodes);
        $this->assertFalse($nodes[0]['supported']);
        $this->assertFalse($nodes[1]['supported']);
    }

    public function test_fingerprint_survives_credential_rotation_and_reordering(): void
    {
        $parser = new Parser();
        $old = $parser->parse("trojan://old@example.com:443#A\nhy2://other@other.example.com:443#B");
        $new = $parser->parse("hy2://other@other.example.com:443#B\ntrojan://new@example.com:443#A");
        $this->assertSame($old[0]['fingerprint'], $new[1]['fingerprint']);
        $this->assertSame('new', $new[1]['config']['password']);
    }

    public function test_conflicting_duplicate_identity_is_not_distributed(): void
    {
        $nodes = (new Parser())->parse("trojan://one@example.com:443#A\ntrojan://two@example.com:443#A");
        $this->assertCount(1, $nodes);
        $this->assertFalse($nodes[0]['supported']);
    }

    public function test_unknown_nested_yaml_settings_are_rejected(): void
    {
        $nodes = (new Parser())->parse("proxies:\n  - name: ws\n    type: trojan\n    server: example.com\n    port: 443\n    password: test\n    network: ws\n    ws-opts:\n      headers:\n        Authorization: never-expose\n");
        $this->assertFalse($nodes[0]['supported']);
        $this->assertStringNotContainsString('never-expose', $nodes[0]['error']);
    }

    public function test_duplicate_query_parameters_are_rejected(): void
    {
        $node = (new Parser())->parse('hy2://pass@example.com?sni=one.example.com&sni=two.example.com#A')[0];
        $this->assertFalse($node['supported']);
    }

    public function test_unsupported_grpc_multi_is_reported(): void
    {
        $node = (new Parser())->parse('vless://' . self::UUID . '@example.com:443?type=grpc&mode=multi#grpc')[0];
        $this->assertFalse($node['supported']);
    }

    public function test_empty_or_broken_response_is_a_sync_failure(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->parse('<html>upstream unavailable</html>');
    }

    public function test_body_size_is_bounded(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->parse(str_repeat('a', Parser::MAX_BYTES + 1));
    }

    public function test_node_count_is_bounded(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->parse(str_repeat("trojan://pass@example.com:443#A\n", Parser::MAX_NODES + 1));
    }
}
