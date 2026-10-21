<?php

namespace App\Console\Commands;

use App\Models\ExternalSubscription;
use App\Services\ExternalSubscription\SyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncExternalSubscriptions extends Command
{
    protected $signature = 'external-subscriptions:sync';
    protected $description = 'Synchronize enabled external subscriptions without logging credentials';

    public function handle(SyncService $sync): int
    {
        $failed = false;
        foreach (ExternalSubscription::where('enabled', true)->orderBy('id')->cursor() as $source) {
            try {
                $counts = $sync->sync($source);
                $this->info('Source #' . $source->id . ': ' . $counts['total'] . ' nodes synchronized.');
            } catch (Throwable $e) {
                $failed = true;
                $this->warn('Source #' . $source->id . ': synchronization failed; inspect its status in the admin panel.');
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
