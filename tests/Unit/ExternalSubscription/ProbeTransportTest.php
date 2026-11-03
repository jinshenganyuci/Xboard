<?php

namespace Tests\Unit\ExternalSubscription;

use App\Services\ExternalSubscription\Fetcher;
use App\Services\ExternalSubscription\Parser;
use App\Services\ExternalSubscription\ProbeTransport;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProbeTransportTest extends TestCase
{
    private function harness(array $addresses = ['8.8.8.8'], bool $baseline = true): ProbeTransport
    {
        return new class(new Fetcher(), $addresses, $baseline) extends ProbeTransport {
            public int $lookups = 0;
            public array $controls = [];

            public function __construct(Fetcher $fetcher, private array $ips, private bool $baseline)
            {
                parent::__construct($fetcher);
            }

            protected function resolveDns(string $host): array
            {
                $this->lookups++;
                return $this->ips;
            }

            protected function request204(string $target, array $networkOptions, int $timeoutMs): ?bool
            {
                $this->controls[] = [$target, $networkOptions, $timeoutMs];
                return $this->baseline;
            }

            public function outbound(string $uri, bool $backup = false): array
            {
                $node = (new Parser())->parse($uri)[0];
                if (!$node['supported']) {
                    throw new RuntimeException('Invalid synthetic fixture.');
                }
                return $this->pinnedOutbound($node['config'], $backup);
            }

            public function program(array $outbound): array
            {
                return $this->configuration($outbound, 42424, 'synthetic-user', 'synthetic-password');
            }

            public function outcome(bool $ok, int $error, int $status, int $bytes = 0): ?bool
            {
                return $this->classify($ok, $error, $status, $bytes);
            }

            public function control(?bool $value, bool $backup): ?bool
            {
                return $this->applyBackupControl($value, $backup);
            }
        };
    }

    public function test_pins_a_vetted_address_without_losing_tls_or_transport_identity(): void
    {
        $probe = $this->harness(['2001:4860:4860::8888', '8.8.8.8']);
        $uri = 'vless://11111111-2222-4333-8444-555555555555@node.example.com:443?security=tls&type=ws&path=%2Fprobe#Original';
        $first = $probe->outbound($uri);
        $this->assertSame('8.8.8.8', $first['server']);
        $this->assertSame('node.example.com', $first['tls']['server_name']);
        $this->assertSame('node.example.com', $first['transport']['headers']['Host']);
        $this->assertSame('/probe', $first['transport']['path']);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $first['uuid']);
        $this->assertSame('2001:4860:4860::8888', $probe->outbound($uri, true)['server']);
        $this->assertSame(2, $probe->lookups);

        $explicit = $probe->outbound('trojan://s%40cret@node.example.com:443?security=tls&type=grpc&sni=tls.example.com&serviceName=service#Trojan');
        $this->assertSame('s@cret', $explicit['password']);
        $this->assertSame('tls.example.com', $explicit['tls']['server_name']);
        $this->assertSame('service', $explicit['transport']['service_name']);
    }

    public function test_core_has_only_an_authenticated_loopback_listener_and_one_proxy_outbound(): void
    {
        $probe = $this->harness();
        $outbound = $probe->outbound('hy2://p%40ss@11.254.233.2:443?sni=hy.example.com&mport=443%2C8443-8450&obfs=salamander&obfs-password=synthetic#Node');
        $this->assertSame(0, $probe->lookups, 'A vetted IP literal needs no resolver.');
        $this->assertSame('hysteria2', $outbound['type']);
        $this->assertSame('p@ss', $outbound['password']);
        $this->assertSame(['443:443', '8443:8450'], $outbound['server_ports']);
        $this->assertSame('synthetic', $outbound['obfs']['password']);
        $program = $probe->program($outbound);
        $this->assertSame([$outbound], $program['outbounds']);
        $this->assertSame($outbound['tag'], $program['route']['final']);
        $this->assertSame(['disabled' => true], $program['log']);
        $this->assertCount(1, $program['inbounds']);
        $this->assertSame('127.0.0.1', $program['inbounds'][0]['listen']);
        $this->assertSame('socks', $program['inbounds'][0]['type']);
        $this->assertSame([['username' => 'synthetic-user', 'password' => 'synthetic-password']], $program['inbounds'][0]['users']);
    }

    public static function forbiddenAddresses(): array
    {
        return [[['8.8.8.8', '127.0.0.1']], [['169.254.169.254']], [['10.0.0.1']], [['198.18.0.1']],
            [['::ffff:127.0.0.1']], [['2001:db8::1']], [['64:ff9b::7f00:1']], [['2002:7f00:1::1']], [[]]];
    }

    #[DataProvider('forbiddenAddresses')]
    public function test_mixed_or_reserved_dns_never_becomes_an_outbound(array $addresses): void
    {
        $this->expectException(RuntimeException::class);
        $this->harness($addresses)->outbound('trojan://synthetic@node.example.com:443#Blocked');
    }

    public function test_only_empty_204_certifies_success_and_target_or_local_errors_remain_unknown(): void
    {
        $probe = $this->harness();
        $this->assertTrue($probe->outcome(true, 0, 204));
        foreach ([[true, 0, 200], [true, 0, 302], [true, 0, 503], [false, 60, 0], [false, 7, 0], [true, 0, 204, 1]] as $error) {
            $this->assertNull($probe->outcome(...$error));
        }
        $this->assertFalse($probe->outcome(false, 97, 0));
        $this->assertFalse($probe->outcome(false, CURLE_OPERATION_TIMEDOUT, 0));
    }

    public function test_direct_control_only_vetoes_a_failed_backup_and_is_shared_briefly(): void
    {
        config()->set('cache.default', 'array');
        Cache::store('array')->flush();
        $probe = $this->harness();
        $this->assertFalse($probe->control(false, false));
        $this->assertTrue($probe->control(true, true));
        $this->assertNull($probe->control(null, true));
        $this->assertSame([], $probe->controls);
        $this->assertFalse($probe->control(false, true), 'A healthy direct path must never promote a failed proxy.');
        $this->assertFalse($probe->control(false, true));
        $this->assertSame([['https://cp.cloudflare.com/generate_204', [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'], 2500]], $probe->controls);

        $unreachable = $this->harness(baseline: false);
        $this->travel(31)->seconds();
        try {
            $this->assertNull($unreachable->control(false, true), 'Target or local egress outage must suppress an offline verdict.');
            $this->assertNull($unreachable->control(false, true));
            $this->assertCount(1, $unreachable->controls);
        } finally {
            $this->travelBack();
        }
    }
}
