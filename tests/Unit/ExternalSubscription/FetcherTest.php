<?php

namespace Tests\Unit\ExternalSubscription;

use App\Services\ExternalSubscription\Fetcher;
use App\Services\ExternalSubscription\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FetcherTest extends TestCase
{
    public static function forbiddenTargets(): array
    {
        return array_map(fn ($url) => [$url], [
            'file:///etc/passwd', 'http://127.0.0.1/feed', 'http://10.0.0.1/feed',
            'http://169.254.169.254/latest/meta-data', 'http://172.16.0.1/feed',
            'http://192.168.1.1/feed', 'http://100.64.0.1/feed', 'http://224.0.0.1/feed',
            'http://198.51.100.1/feed', 'http://198.18.0.1/feed', 'http://198.19.0.1/feed',
            'http://[::1]/feed', 'http://[::ffff:127.0.0.1]/feed', 'http://[64:ff9b::7f00:1]/feed',
            'http://[2002:7f00:1::]/feed', 'http://localhost/feed',
            'https://user:password@example.com/feed', 'https://example.com/feed#fragment',
            'http://2130706433/feed', 'http://0x7f000001/feed', 'http://127.1/feed',
        ]);
    }

    #[DataProvider('forbiddenTargets')]
    public function test_forbids_non_public_targets_before_connecting(string $url): void
    {
        $fetcher = new FakeExternalFetcher();
        try {
            $fetcher->fetch($url);
            $this->fail('Unsafe URL was accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame([], $fetcher->requests);
            $this->assertStringNotContainsString($url, $e->getMessage());
        }
    }

    public function test_dns_result_is_pinned_for_request(): void
    {
        $fetcher = new FakeExternalFetcher();
        $fetcher->dns = [['93.184.216.34']];
        $this->assertSame('body', $fetcher->fetch('https://example.com/feed?token=never-print'));
        $this->assertSame(['host' => 'example.com', 'port' => 443, 'address' => '93.184.216.34'], $fetcher->requests[0]);
        $this->assertSame(1, $fetcher->lookups);
    }

    public function test_public_address_adjacent_to_documentation_range_is_allowed(): void
    {
        $fetcher = new FakeExternalFetcher();
        $this->assertSame('body', $fetcher->fetch('http://198.51.101.1/feed'));
        $this->assertSame('198.51.101.1', $fetcher->requests[0]['address']);
        $this->assertSame(0, $fetcher->lookups);
    }

    public function test_mixed_private_and_public_dns_answers_are_rejected(): void
    {
        $fetcher = new FakeExternalFetcher();
        $fetcher->dns = [['93.184.216.34', '127.0.0.1']];
        $this->expectException(RuntimeException::class);
        $fetcher->fetch('https://example.com/feed');
    }

    public function test_redirect_rechecks_dns_and_blocks_rebinding(): void
    {
        $fetcher = new FakeExternalFetcher();
        $fetcher->dns = [['93.184.216.34'], ['10.0.0.1']];
        $fetcher->responses = [['status' => 302, 'location' => '/next', 'body' => '']];
        try {
            $fetcher->fetch('https://example.com/feed');
            $this->fail('Rebound redirect was accepted.');
        } catch (RuntimeException $e) {
            $this->assertCount(1, $fetcher->requests);
            $this->assertSame(2, $fetcher->lookups);
        }
    }

    public function test_redirect_to_metadata_endpoint_is_blocked(): void
    {
        $fetcher = new FakeExternalFetcher();
        $fetcher->responses = [['status' => 302, 'location' => 'https://169.254.169.254/secret', 'body' => '']];
        try {
            $fetcher->fetch('https://example.com/feed');
            $this->fail('Private redirect was accepted.');
        } catch (RuntimeException $e) {
            $this->assertCount(1, $fetcher->requests);
        }
    }

    public function test_tls_downgrade_is_rejected(): void
    {
        $fetcher = new FakeExternalFetcher();
        $fetcher->responses = [['status' => 302, 'location' => 'http://example.com/feed', 'body' => '']];
        $this->expectException(RuntimeException::class);
        $fetcher->fetch('https://example.com/feed');
    }

    public function test_oversized_response_and_transport_errors_do_not_expose_secrets(): void
    {
        $fetcher = new FakeExternalFetcher();
        $fetcher->responses = [['status' => 200, 'location' => null, 'body' => str_repeat('a', Parser::MAX_BYTES + 1)]];
        try {
            $fetcher->fetch('https://example.com/feed?token=never-print');
            $this->fail('Oversized response was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('never-print', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }
}

class FakeExternalFetcher extends Fetcher
{
    public array $dns = [['93.184.216.34']];
    public array $responses = [['status' => 200, 'location' => null, 'body' => 'body']];
    public array $requests = [];
    public int $lookups = 0;

    protected function resolveAddresses(string $host): array
    {
        $this->lookups++;
        return count($this->dns) > 1 ? array_shift($this->dns) : $this->dns[0];
    }

    protected function request(string $url, array $target): array
    {
        $this->requests[] = $target;
        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }
}
