<?php

namespace Tests\Feature\ExternalSubscription;

use App\Models\ExternalSubscription;
use App\Models\User;
use App\Services\ExternalSubscription\Fetcher;
use App\Services\ExternalSubscription\NodeProvider;
use App\Services\ExternalSubscription\Parser;
use App\Services\ExternalSubscription\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('x', 32)));
        config()->set('cache.default', 'array');
    }

    private function source(array $overrides = []): ExternalSubscription
    {
        return ExternalSubscription::create($overrides + ['name' => 'Example', 'url' => 'https://example.com/sub?token=private-token', 'enabled' => true]);
    }

    private function syncBody(ExternalSubscription $source, string $body): array
    {
        $fetcher = Mockery::mock(Fetcher::class);
        $fetcher->shouldReceive('fetch')->once()->with($source->url)->andReturn($body);
        return (new SyncService($fetcher, new Parser()))->sync($source);
    }

    public function test_credential_updates_and_order_changes_keep_selection_new_nodes_are_unselected(): void
    {
        $source = $this->source();
        $first = $this->syncBody($source, "trojan://old@example.com:443#A\nhy2://pass@other.example.com:443#B");
        $this->assertSame(2, $first['added']);
        $node = $source->nodes()->where('name', 'A')->firstOrFail();
        $this->assertFalse($node->selected);
        $node->update(['selected' => true]);
        $this->syncBody($source, "hy2://pass@other.example.com:443#B\ntrojan://new@example.com:443#A\ntrojan://pass@third.example.com:443#C");
        $node->refresh();
        $this->assertTrue($node->selected);
        $this->assertSame('new', $node->config['password']);
        $this->assertSame(1, $node->sort_order);
        $this->assertFalse($source->nodes()->where('name', 'C')->firstOrFail()->selected);
        $this->assertSame(3, $source->nodes()->count());
    }

    public function test_removed_nodes_are_marked_absent_and_not_distributed(): void
    {
        $source = $this->source();
        $this->syncBody($source, "trojan://pass@example.com:443#A\nhy2://pass@other.example.com:443#B");
        $source->nodes()->update(['selected' => true]);
        $counts = $this->syncBody($source, 'hy2://pass@other.example.com:443#B');
        $this->assertSame(1, $counts['removed']);
        $this->assertFalse($source->nodes()->where('name', 'A')->firstOrFail()->present);
        $nodes = (new NodeProvider())->forUser($this->eligibleUser());
        $this->assertCount(1, $nodes);
        $this->assertStringStartsWith('B', $nodes[0]['name']);
    }

    public function test_failure_keeps_last_good_snapshot_and_redacts_errors(): void
    {
        $source = $this->source();
        $this->syncBody($source, 'trojan://good-password@example.com:443#A');
        $source->nodes()->update(['selected' => true]);
        $before = $source->fresh()->last_synced_at->toIso8601String();
        $fetcher = Mockery::mock(Fetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andThrow(new RuntimeException('https://example.com/sub?token=private-token'));
        try {
            (new SyncService($fetcher, new Parser()))->sync($source);
            $this->fail('Failed download was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('private-token', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $source->refresh();
        $this->assertSame($before, $source->last_synced_at->toIso8601String());
        $this->assertStringNotContainsString('private-token', $source->last_error);
        $this->assertSame('good-password', $source->nodes()->firstOrFail()->config['password']);
        $this->assertCount(1, (new NodeProvider())->forUser($this->eligibleUser()));
    }

    public function test_invalid_html_does_not_replace_snapshot(): void
    {
        $source = $this->source();
        $this->syncBody($source, 'trojan://pass@example.com:443#A');
        try {
            $this->syncBody($source, '<html>not authorized</html>');
            $this->fail('HTML was accepted.');
        } catch (RuntimeException $e) {
            $this->assertTrue($source->nodes()->firstOrFail()->present);
        }
    }

    public function test_source_urls_and_node_credentials_are_encrypted_and_hidden(): void
    {
        $source = $this->source();
        $this->syncBody($source, 'trojan://node-secret@example.com:443#A');
        $node = $source->nodes()->firstOrFail();
        $this->assertStringNotContainsString('private-token', DB::table('external_subscriptions')->value('url'));
        $this->assertStringNotContainsString('node-secret', DB::table('external_subscription_nodes')->value('config'));
        $this->assertArrayNotHasKey('url', $source->toArray());
        $this->assertArrayNotHasKey('config', $node->toArray());
    }

    public function test_provider_uses_active_plan_without_traffic_limits_and_respects_switches(): void
    {
        $source = $this->source();
        $this->syncBody($source, 'trojan://pass@example.com:443#Same');
        $source->nodes()->update(['selected' => true]);
        $other = $this->source(['name' => 'Other']);
        $this->syncBody($other, 'trojan://pass@example.com:443#Same');
        $other->nodes()->update(['selected' => true]);
        $provider = new NodeProvider();
        $user = $this->eligibleUser();
        $nodes = $provider->forUser($user);
        $this->assertCount(2, $nodes);
        $this->assertNotSame($nodes[0]['name'], $nodes[1]['name']);
        $this->assertLessThan(0, $nodes[0]['id']);
        foreach ([['banned' => 1], ['expired_at' => time() - 1], ['plan_id' => null]] as $changes) {
            $this->assertSame([], $provider->forUser($this->eligibleUser()->forceFill($changes)));
        }
        $source->update(['enabled' => false]);
        $other->nodes()->update(['supported' => false]);
        $this->assertSame([], $provider->forUser($user));
    }

    public function test_overlapping_sync_is_rejected(): void
    {
        $source = $this->source();
        $lock = Cache::lock('external-subscription:' . $source->id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->expectException(RuntimeException::class);
            (new SyncService(Mockery::mock(Fetcher::class), new Parser()))->sync($source);
        } finally {
            $lock->release();
        }
    }

    public function test_source_edit_during_fetch_discards_stale_results(): void
    {
        $source = $this->source();
        $this->syncBody($source, 'trojan://old@example.com:443#A');
        $fetcher = Mockery::mock(Fetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andReturnUsing(function () use ($source) {
            $source->update(['url' => 'https://other.example.com/new?token=changed']);
            return 'trojan://stale@example.com:443#A';
        });
        try {
            (new SyncService($fetcher, new Parser()))->sync($source);
            $this->fail('Stale sync was accepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('old', $source->nodes()->firstOrFail()->config['password']);
            $this->assertNull($source->fresh()->last_error);
        }
    }

    private function eligibleUser(): User
    {
        return (new User())->forceFill(['plan_id' => 1, 'banned' => 0, 'expired_at' => time() + 3600, 'transfer_enable' => 0, 'u' => 1000, 'd' => 1000]);
    }
}
