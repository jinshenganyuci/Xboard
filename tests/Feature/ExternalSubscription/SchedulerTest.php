<?php

namespace Tests\Feature\ExternalSubscription;

use App\Models\ExternalSubscription;
use App\Services\ExternalSubscription\Fetcher;
use App\Services\ExternalSubscription\Parser;
use App\Services\ExternalSubscription\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('s', 32)));
        config()->set('cache.default', 'array');
        Cache::store('array')->flush();
        $this->travelTo(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function source(array $attributes = []): ExternalSubscription
    {
        return ExternalSubscription::create($attributes + [
            'name' => 'Scheduled example', 'url' => 'https://example.com/sub?token=synthetic-secret', 'enabled' => true,
        ]);
    }

    public function test_due_sync_refreshes_new_and_five_minute_old_sources_but_skips_recent_and_disabled_sources(): void
    {
        $new = $this->source();
        $old = $this->source(['last_synced_at' => now()->subMinutes(6)]);
        $boundary = $this->source(['last_synced_at' => now()->subMinutes(5)]);
        $recent = $this->source(['last_synced_at' => now()->subSeconds(299)]);
        $disabled = $this->source(['enabled' => false]);
        $seen = [];
        $sync = Mockery::mock(SyncService::class);
        $sync->shouldReceive('sync')->times(3)->andReturnUsing(function ($source) use (&$seen) {
            $seen[] = $source->id;
            $source->update(['last_synced_at' => now()]);
            return ['total' => 1];
        });
        $this->app->instance(SyncService::class, $sync);

        $this->assertSame(0, Artisan::call('external-subscriptions:sync', ['--due' => true]));
        $this->assertSame([$new->id, $old->id, $boundary->id], $seen);
        $this->assertSame(0, Artisan::call('external-subscriptions:sync', ['--due' => true]));
        $this->assertSame([$new->id, $old->id, $boundary->id], $seen);
        $this->assertSame(now()->subSeconds(299)->timestamp, $recent->fresh()->last_synced_at->timestamp);
        $this->assertNull($disabled->fresh()->last_synced_at);
    }

    public function test_failure_retries_after_one_minute_and_does_not_expose_the_upstream_error(): void
    {
        $source = $this->source();
        $attempts = 0;
        $sync = Mockery::mock(SyncService::class);
        $sync->shouldReceive('sync')->twice()->andReturnUsing(function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('https://example.com/sub?token=synthetic-secret');
        });
        $this->app->instance(SyncService::class, $sync);

        $this->assertSame(1, Artisan::call('external-subscriptions:sync', ['--due' => true]));
        $this->assertStringNotContainsString('synthetic-secret', Artisan::output());
        $this->assertSame(0, Artisan::call('external-subscriptions:sync', ['--due' => true]));
        $this->travel(59)->seconds();
        $this->assertSame(0, Artisan::call('external-subscriptions:sync', ['--due' => true]));
        $this->assertSame(1, $attempts);
        $this->travel(2)->seconds();
        $this->assertSame(1, Artisan::call('external-subscriptions:sync', ['--due' => true]));
        $this->assertSame(2, $attempts);
        $this->assertNull($source->fresh()->last_synced_at);
    }

    public function test_manual_sync_bypasses_automatic_freshness_cooldown_and_dispatch_lock(): void
    {
        $source = $this->source(['last_synced_at' => now()]);
        $this->source(['enabled' => false]);
        Cache::put('external-subscriptions:attempt:' . $source->id, true, 60);
        $lock = Cache::lock('external-subscriptions:due:' . $source->id, 180);
        $this->assertTrue($lock->get());
        $sync = Mockery::mock(SyncService::class);
        $sync->shouldReceive('sync')->once()->withArgs(fn ($item) => $item->id === $source->id)->andReturn(['total' => 1]);
        $this->app->instance(SyncService::class, $sync);
        try {
            $this->assertSame(0, Artisan::call('external-subscriptions:sync'));
        } finally {
            $lock->release();
        }
    }

    public function test_another_dispatcher_lock_skips_only_its_source_and_releases_after_completion(): void
    {
        $busy = $this->source();
        $ready = $this->source();
        $lock = Cache::lock('external-subscriptions:due:' . $busy->id, 180);
        $this->assertTrue($lock->get());
        $seen = [];
        $sync = Mockery::mock(SyncService::class);
        $sync->shouldReceive('sync')->twice()->andReturnUsing(function ($source) use (&$seen) {
            $seen[] = $source->id;
            $source->update(['last_synced_at' => now()]);
            return ['total' => 1];
        });
        $this->app->instance(SyncService::class, $sync);
        try {
            $this->assertSame(0, Artisan::call('external-subscriptions:sync', ['--due' => true]));
            $this->assertSame([$ready->id], $seen);
        } finally {
            $lock->release();
        }
        $this->assertSame(0, Artisan::call('external-subscriptions:sync', ['--due' => true]));
        $this->assertSame([$ready->id, $busy->id], $seen);
        $released = Cache::lock('external-subscriptions:due:' . $busy->id, 180);
        $this->assertTrue($released->get());
        $released->release();
    }

    public function test_existing_source_lock_blocks_automatic_fetch_until_manual_work_finishes(): void
    {
        $source = $this->source();
        $lock = Cache::lock('external-subscription:' . $source->id, 120);
        $this->assertTrue($lock->get());
        $fetcher = Mockery::mock(Fetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andReturn('trojan://synthetic@example.com:443#Example');
        $this->app->instance(SyncService::class, new SyncService($fetcher, new Parser()));
        try {
            $this->assertSame(1, Artisan::call('external-subscriptions:sync', ['--due' => true]));
            $this->assertSame(0, $source->nodes()->count());
        } finally {
            $lock->release();
        }
        $this->travel(61)->seconds();
        $this->assertSame(0, Artisan::call('external-subscriptions:sync', ['--due' => true]));
        $this->assertSame(1, $source->nodes()->count());
    }

    public function test_environment_switch_disables_automatic_runs_but_keeps_manual_sync_available(): void
    {
        $this->source();
        $key = 'ENABLE_EXTERNAL_SUBSCRIPTIONS_SYNC';
        $before = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = 'false';
        putenv($key . '=false');
        $sync = Mockery::mock(SyncService::class);
        $sync->shouldReceive('sync')->once()->andReturn(['total' => 1]);
        $this->app->instance(SyncService::class, $sync);
        try {
            $this->assertSame(0, Artisan::call('external-subscriptions:sync', ['--due' => true]));
            $this->assertSame(0, Artisan::call('external-subscriptions:sync'));
        } finally {
            foreach (['_ENV', '_SERVER'] as $index => $global) {
                if ($before[$index] === null) {
                    unset($GLOBALS[$global][$key]);
                } else {
                    $GLOBALS[$global][$key] = $before[$index];
                }
            }
            putenv($before[2] === false ? $key : $key . '=' . $before[2]);
        }
    }
}
