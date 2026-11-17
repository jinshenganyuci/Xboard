<?php

namespace Tests\Feature\ExternalSubscription;

use App\Http\Controllers\V2\Admin\ExternalSubscriptionController;
use App\Models\ExternalSubscription;
use App\Models\ExternalSubscriptionNode;
use App\Services\ExternalSubscription\Fetcher;
use App\Services\ExternalSubscription\HealthService;
use App\Services\ExternalSubscription\Parser;
use App\Services\ExternalSubscription\ProbeResult;
use App\Services\ExternalSubscription\ProbeTransport;
use App\Services\ExternalSubscription\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function source(bool $enabled = true): ExternalSubscription
    {
        return ExternalSubscription::create(['name' => 'Health fixture', 'url' => 'https://fixture.example/sub', 'enabled' => $enabled]);
    }

    private function node(ExternalSubscription $source, string $name = 'Example', array $attributes = []): ExternalSubscriptionNode
    {
        return $source->nodes()->create($attributes + [
            'fingerprint' => hash('sha256', $name), 'name' => $name, 'type' => 'trojan',
            'selected' => true, 'present' => true, 'supported' => true,
            'config' => ['name' => $name, 'host' => 'upstream.example', 'port' => 443, 'password' => 'fixture-password'],
            'health_due_at' => now()->timestamp,
        ])->fresh();
    }

    private function service(callable $check): HealthService
    {
        $transport = Mockery::mock(ProbeTransport::class);
        $transport->shouldReceive('measure')->andReturnUsing(function (...$arguments) use ($check) {
            $answer = $check(...$arguments);
            return $answer instanceof ProbeResult ? $answer : new ProbeResult($answer, 120);
        });
        return new HealthService($transport);
    }

    private function syncBody(ExternalSubscription $source, string $body): void
    {
        $fetcher = Mockery::mock(Fetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andReturn($body);
        (new SyncService($fetcher, new Parser()))->sync($source);
    }

    public function test_primary_failure_is_confirmed_on_backup_and_offline_nodes_recover_hourly(): void
    {
        $node = $this->node($this->source());
        $attempts = [];
        $answers = [true, false, false, true];
        $health = $this->service(function ($config, $backup) use (&$attempts, &$answers) {
            $attempts[] = $backup;
            $this->assertSame('fixture-password', $config['password']);
            return array_shift($answers);
        });
        $this->assertSame(['health_status' => 'unknown', 'is_online' => null, 'last_check_at' => null, 'latency_ms' => null], $health->status($node));
        $this->assertSame(1, $health->scan(0, 8));
        $this->assertSame(['health_status' => 'online', 'is_online' => true, 'last_check_at' => now()->timestamp, 'latency_ms' => 120], $health->status($node->fresh()));
        $this->travel(3599)->seconds();
        $this->assertSame(0, $health->scan(0, 8));
        $this->travel(1)->seconds();
        $this->assertSame(1, $health->scan(0, 8));
        $this->assertSame(['health_status' => 'pending', 'is_online' => null, 'last_check_at' => now()->timestamp, 'latency_ms' => null], $health->status($node->fresh()));
        $this->assertNull($node->fresh()->health_latency_ms);
        $this->travel(299)->seconds();
        $this->assertSame(0, $health->scan(0, 8));
        $this->travel(1)->seconds();
        $this->assertSame(1, $health->scan(1, 8));
        $this->assertSame(['health_status' => 'offline', 'is_online' => false, 'last_check_at' => now()->timestamp, 'latency_ms' => null], $health->status($node->fresh()));
        $this->travel(3600)->seconds();
        $this->assertSame(1, $health->scan(1, 8));
        $this->assertTrue($health->status($node->fresh())['is_online']);
        $this->assertSame([false, false, true, false], $attempts);
        $checked = now()->timestamp;
        $this->travel(7201)->seconds();
        $this->assertSame(['health_status' => 'unknown', 'is_online' => null, 'last_check_at' => $checked, 'latency_ms' => null], $health->status($node->fresh()));
    }

    public function test_infrastructure_failure_cannot_keep_old_online_or_confirm_offline(): void
    {
        $node = $this->node($this->source(), attributes: ['health_status' => 'online', 'health_checked_at' => now()->timestamp - 3600,
            'health_latency_ms' => 99]);
        $attempts = [];
        $health = $this->service(function ($config, $backup) use (&$attempts) {
            $attempts[] = $backup;
            return match (count($attempts)) {
                1 => null, 2 => false, 3 => throw new RuntimeException('private-endpoint-token'), 4 => true,
            };
        });
        $this->app->instance(HealthService::class, $health);
        foreach ([['unknown', 3600], ['pending', 300], ['unknown', 3600], ['online', 3600]] as [$state, $delay]) {
            $this->assertSame(0, Artisan::call('external-subscriptions:health', ['--slot' => '0', '--limit' => '1']));
            $this->assertStringNotContainsString('private-endpoint-token', Artisan::output());
            $this->assertSame($state, $health->status($node->fresh())['health_status']);
            $this->assertSame($state === 'online' ? 120 : null, $node->fresh()->health_latency_ms);
            $this->assertSame(now()->timestamp + $delay, $node->fresh()->health_due_at);
            $this->travel($delay - 1)->seconds();
            $this->assertSame(0, $health->scan(1, 1));
            $this->travel(1)->seconds();
        }
        $this->assertSame([false, false, true, false], $attempts);
    }

    public function test_only_eligible_nodes_are_scheduled_and_initial_work_is_spread_out(): void
    {
        $source = $this->source();
        $eligible = collect();
        foreach (range(1, 10) as $id) {
            $eligible->push($this->node($source, 'Node ' . $id, ['health_due_at' => null]));
        }
        $disabled = $this->node($this->source(false), 'Disabled');
        $notSelected = $this->node($source, 'Not selected', ['selected' => false]);
        $missing = $this->node($source, 'Missing', ['present' => false]);
        $unsupported = $this->node($source, 'Unsupported', ['supported' => false]);
        $seen = [];
        $health = $this->service(function ($config) use (&$seen) {
            $seen[] = $config['name'];
            return true;
        });
        $start = now()->timestamp;
        $health->scan(0, 2);
        $due = $eligible->map(fn ($node) => $node->fresh()->health_due_at);
        $this->assertGreaterThan(1, $due->unique()->count());
        $this->assertGreaterThanOrEqual($start, $due->min());
        $this->travel(60)->seconds();
        $this->assertSame(2, $health->scan(0, 2));
        foreach ([$disabled, $notSelected, $missing, $unsupported] as $node) {
            $this->assertNotContains($node->name, $seen);
            $this->assertNull($node->fresh()->health_checked_at);
        }
    }

    public function test_concurrent_slots_cannot_probe_the_same_node_or_exceed_two_active_probes(): void
    {
        $source = $this->source();
        $this->node($source, 'A');
        $this->node($source, 'B');
        $this->node($source, 'C');
        $seen = [];
        $health = $this->service(function ($config) use (&$seen, &$health) {
            $seen[] = $config['name'];
            if ($config['name'] === 'A') {
                $this->assertSame(0, $health->scan(0, 1));
                $this->assertSame(1, $health->scan(1, 1));
            } else {
                $this->assertSame(0, $health->scan(0, 1));
                $this->assertSame(0, $health->scan(1, 1));
            }
            return true;
        });
        $this->assertSame(1, $health->scan(0, 1));
        $this->assertSame(['A', 'B'], $seen);
        $this->assertNull($source->nodes()->where('name', 'C')->value('health_checked_at'));
    }

    public function test_identical_sync_preserves_state_but_credential_rotation_invalidates_in_flight_result(): void
    {
        $source = $this->source();
        $initial = 'hy2://0e123@upstream.example:443?upmbps=10#Health';
        $this->syncBody($source, $initial);
        $node = $source->nodes()->firstOrFail();
        $node->update(['selected' => true, 'health_due_at' => now()->timestamp]);
        $ciphertext = $node->getRawOriginal('config');
        $healthy = $this->service(fn () => true);
        $healthy->scan(0, 1);
        $revision = $node->fresh()->health_revision;
        $this->syncBody($source, $initial);
        $node->refresh();
        $this->assertSame($revision, $node->health_revision);
        $this->assertSame($ciphertext, $node->getRawOriginal('config'));
        $this->assertTrue($healthy->status($node)['is_online']);
        $this->assertSame(120, $healthy->status($node)['latency_ms']);

        $node->update(['health_due_at' => now()->timestamp]);
        $changed = $this->service(function () use ($source) {
            $this->syncBody($source, 'hy2://0e456@upstream.example:443?upmbps=10#Health');
            return true;
        });
        $changed->scan(0, 1);
        $node->refresh();
        $this->assertSame('0e456', $node->config['password']);
        $this->assertTrue($node->selected);
        $this->assertNull($node->health_due_at);
        $this->assertNull($node->health_latency_ms);
        $this->assertSame(['health_status' => 'unknown', 'is_online' => null, 'last_check_at' => null, 'latency_ms' => null], $changed->status($node));

        $node->update(['health_due_at' => now()->timestamp]);
        $removed = $this->service(function () use ($source) {
            $this->syncBody($source, 'proxies: []');
            return true;
        });
        $removed->scan(0, 1);
        $this->assertFalse($source->nodes()->exists());
    }

    public function test_selection_and_source_switches_clear_state_only_for_changed_nodes(): void
    {
        $source = $this->source();
        $old = $this->node($source, 'A', ['health_status' => 'online', 'health_checked_at' => now()->timestamp, 'health_latency_ms' => 145]);
        $other = $this->node($source, 'B', ['selected' => false]);
        $controller = app(ExternalSubscriptionController::class);
        $select = fn ($ids) => $controller->select(Request::create('/', 'POST', ['id' => $source->id, 'node_ids' => $ids]));
        $save = fn ($enabled) => $controller->save(Request::create('/', 'POST', ['id' => $source->id, 'name' => $source->name, 'enabled' => $enabled]));
        $select([$old->id, $other->id]);
        $this->assertSame('online', $old->refresh()->health_status);
        $this->assertSame(145, $old->health_latency_ms);
        $this->assertSame(0, $old->health_revision);
        $this->assertSame(1, $other->refresh()->health_revision);
        $this->assertNull($other->health_due_at);
        $select([$other->id]);
        $select([$old->id, $other->id]);
        $this->assertSame('unknown', $old->refresh()->health_status);
        $this->assertNull($old->health_checked_at);
        $this->assertNull($old->health_latency_ms);
        $this->assertSame(2, $old->health_revision);
        $save(false);
        $save(true);
        $this->assertSame(4, $old->refresh()->health_revision);
        $this->assertSame(3, $other->refresh()->health_revision);
    }

    public function test_each_node_keeps_its_own_measurement_and_ineligible_nodes_hide_cached_latency(): void
    {
        $source = $this->source();
        $online = $this->node($source, 'Measured');
        $failed = $this->node($source, 'Failed');
        $health = $this->service(fn ($config) => $config['name'] === 'Measured'
            ? new ProbeResult(true, 247) : new ProbeResult(false));
        $this->assertSame(2, $health->scan(0, 2));
        $this->assertSame(247, $online->refresh()->health_latency_ms);
        $this->assertNull($failed->refresh()->health_latency_ms);

        foreach (['selected', 'present', 'supported'] as $attribute) {
            $online->update([$attribute => false]);
            $this->assertNull($health->status($online)['latency_ms']);
            $this->assertNull($health->status($online)['last_check_at']);
            $online->update([$attribute => true]);
        }
        $source->update(['enabled' => false]);
        $this->assertNull($health->status($online)['latency_ms']);
        $source->update(['enabled' => true]);
        $this->assertSame(247, $health->status($online)['latency_ms']);
        $this->travel(7200)->seconds();
        $this->assertNull($health->status($online)['latency_ms']);
    }

    public function test_source_disable_during_probe_cannot_restore_old_latency(): void
    {
        $source = $this->source();
        $node = $this->node($source, attributes: ['health_status' => 'online',
            'health_checked_at' => now()->timestamp - 3600, 'health_latency_ms' => 100]);
        $health = $this->service(function () use ($source) {
            app(ExternalSubscriptionController::class)->save(Request::create('/', 'POST', [
                'id' => $source->id, 'name' => $source->name, 'enabled' => false,
            ]));
            return new ProbeResult(true, 321);
        });
        $this->assertSame(1, $health->scan(0, 1));
        $this->assertNull($node->refresh()->health_latency_ms);
        $this->assertSame('unknown', $health->status($node)['health_status']);
    }
}
