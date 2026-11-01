<?php

namespace App\Console\Commands;

use App\Models\ExternalSubscription;
use App\Services\ExternalSubscription\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncExternalSubscriptions extends Command
{
    protected $signature = 'external-subscriptions:sync {--due : Only synchronize sources due for an automatic refresh}';
    protected $description = 'Synchronize enabled external subscriptions without logging credentials';

    private const REFRESH_SECONDS = 300;
    private const RETRY_SECONDS = 60;
    private const AUTO_LOCK_SECONDS = 180;

    public function handle(SyncService $sync): int
    {
        $due = (bool) $this->option('due');
        if ($due && !filter_var(env('ENABLE_EXTERNAL_SUBSCRIPTIONS_SYNC', true), FILTER_VALIDATE_BOOLEAN)) {
            return self::SUCCESS;
        }

        $sources = ExternalSubscription::where('enabled', true)->orderBy('id');
        if ($due) {
            $sources->where(fn ($query) => $query->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', now()->subSeconds(self::REFRESH_SECONDS)));
        }
        $failed = false;
        foreach ($sources->cursor() as $source) {
            $lock = null;
            $locked = false;
            try {
                if ($due) {
                    // Separate from SyncService's source lock, which also protects manual edits.
                    $lock = Cache::lock('external-subscriptions:due:' . $source->id, self::AUTO_LOCK_SECONDS);
                    if (!($locked = $lock->get())) {
                        continue;
                    }
                    // Another runner or a manual sync may have refreshed this queued source.
                    $source = $source->fresh();
                    if (!$source || !$source->enabled
                        || $source->last_synced_at?->gt(now()->subSeconds(self::REFRESH_SECONDS))) {
                        continue;
                    }
                    if (!Cache::add('external-subscriptions:attempt:' . $source->id, true, self::RETRY_SECONDS)) {
                        continue;
                    }
                }
                $counts = $sync->sync($source);
                $this->info('Source #' . $source->id . ': ' . $counts['total'] . ' nodes synchronized.');
            } catch (Throwable $e) {
                $failed = true;
                $this->warn('Source #' . $source->id . ': synchronization failed; inspect its status in the admin panel.');
            } finally {
                if ($locked) {
                    $lock->release();
                }
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
