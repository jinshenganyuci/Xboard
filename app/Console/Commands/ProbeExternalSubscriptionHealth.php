<?php

namespace App\Console\Commands;

use App\Services\ExternalSubscription\HealthService;
use Illuminate\Console\Command;
use Throwable;

class ProbeExternalSubscriptionHealth extends Command
{
    protected $signature = 'external-subscriptions:health {--slot=0 : Worker slot (0 or 1)} {--limit=8 : Maximum due nodes per scan (1–20)}';
    protected $description = 'Probe due published external nodes without logging credentials';

    public function handle(HealthService $health): int
    {
        if (!filter_var(env('ENABLE_EXTERNAL_SUBSCRIPTIONS_HEALTH', true), FILTER_VALIDATE_BOOLEAN)) {
            return self::SUCCESS;
        }
        $slot = filter_var($this->option('slot'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (!in_array($slot, [0, 1], true) || $limit === false || $limit < 1 || $limit > 20) {
            $this->error('Use --slot=0 or 1 and --limit=1 to 20.');
            return self::INVALID;
        }
        try {
            $count = $health->scan($slot, $limit);
            if ($count > 0) {
                $this->info("Checked {$count} external nodes.");
            }
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('External node health scan could not complete.');
            return self::FAILURE;
        }
    }
}
