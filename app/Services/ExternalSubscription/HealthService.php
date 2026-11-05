<?php

namespace App\Services\ExternalSubscription;

use App\Models\ExternalSubscription;
use App\Models\ExternalSubscriptionNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class HealthService
{
    private const PERIOD = 3600;
    private const RETRY = 300;
    private const STALE = 7200;
    private const LEASE = 120;
    private const BATCH_SECONDS = 45;

    public function __construct(private ProbeTransport $transport)
    {
    }

    /** Use in the same database transaction as a change to eligibility or connection settings. */
    public static function invalidatedAttributes(): array
    {
        return [
            'health_status' => 'unknown', 'health_checked_at' => null, 'health_due_at' => null,
            'health_latency_ms' => null,
            'health_revision' => DB::raw('health_revision + 1'),
        ];
    }

    public function status(ExternalSubscriptionNode $node): array
    {
        return $this->statuses([$node])[$node->id];
    }

    /** No credential reads; callers can eager-load source or use one batch lookup. */
    public function statuses(iterable $nodes): array
    {
        $items = [];
        $enabled = [];
        $unloaded = [];
        foreach ($nodes as $node) {
            $items[] = $node;
            if ($node->relationLoaded('source')) {
                $enabled[$node->source_id] = (bool) $node->source?->enabled;
            } else {
                $unloaded[$node->source_id] = true;
            }
        }
        $unloaded = array_diff_key($unloaded, $enabled);
        if ($unloaded) {
            foreach (ExternalSubscription::whereIn('id', array_keys($unloaded))->where('enabled', true)->pluck('id') as $id) {
                $enabled[$id] = true;
            }
        }
        $now = now()->timestamp;
        $result = [];
        foreach ($items as $node) {
            $eligible = !empty($enabled[$node->source_id]) && $node->selected && $node->present && $node->supported;
            $at = $eligible && $node->health_checked_at !== null ? (int) $node->health_checked_at : null;
            $state = $at !== null && $at > $now - self::STALE && in_array($node->health_status, ['online', 'offline', 'pending'], true)
                ? $node->health_status : 'unknown';
            $result[$node->id] = [
                'health_status' => $state,
                'is_online' => match ($state) { 'online' => true, 'offline' => false, default => null },
                'last_check_at' => $at,
                'latency_ms' => $state === 'online' ? $node->health_latency_ms : null,
            ];
        }
        return $result;
    }

    /** Each of the two slots probes sequentially; a stalled/crashed claim expires. */
    public function scan(int $slot, int $limit): int
    {
        if (!in_array($slot, [0, 1], true) || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Invalid probe slot or batch size.');
        }
        // A process-local cache driver must not allow multiple workers in one slot.
        $lock = Cache::store('redis')->lock('external-health:worker:' . $slot, self::LEASE);
        if (!$lock->get()) {
            return 0;
        }

        try {
            $now = now()->timestamp;
            // Small selections start promptly; bulk imports enter the hourly
            // cycle gradually instead of launching a burst once per hour.
            $unscheduled = $this->eligible()->whereNull('health_due_at');
            if ($new = $unscheduled->count()) {
                $window = min(self::PERIOD, max(30, $new * 6));
                $unscheduled->update(['health_due_at' => DB::raw($now . " + ((id % 3600) * 1597 % {$window})")]);
            }
            $candidates = $this->eligible()->where('health_due_at', '<=', $now)
                ->orderBy('health_due_at')->orderBy('id')->limit($limit * 2)->get();
            $deadline = hrtime(true) + self::BATCH_SECONDS * 1000000000;
            $count = 0;
            foreach ($candidates as $node) {
                if ($count >= $limit || hrtime(true) >= $deadline) {
                    break;
                }
                $revision = (int) $node->health_revision;
                $claimed = $this->eligible()->whereKey($node->id)
                    ->where('health_revision', $revision)->where('health_due_at', '<=', now()->timestamp)
                    ->update(['health_revision' => $revision + 1, 'health_due_at' => now()->timestamp + self::LEASE]);
                if (!$claimed) {
                    continue;
                }
                $count++;
                $backup = $node->health_status === 'pending'
                    && (int) $node->health_checked_at > now()->timestamp - self::STALE;
                try {
                    $answer = $this->transport->measure($node->config, $backup);
                } catch (Throwable) {
                    // Never record exception text, endpoints or credentials.
                    $answer = new ProbeResult(null);
                }
                $state = match ($answer->online) {
                    true => 'online',
                    false => $backup ? 'offline' : 'pending',
                    null => 'unknown',
                };
                $finished = now()->timestamp;
                // Spend a second probe in this hour only to confirm a definite
                // proxy failure. Infrastructure failures wait for the next cycle.
                $delay = $state === 'pending' ? self::RETRY : self::PERIOD;
                // Concurrent edits, deletion or a reclaimed probe invalidate this result.
                $this->eligible()->whereKey($node->id)->where('health_revision', $revision + 1)->update([
                    'health_status' => $state, 'health_checked_at' => $finished,
                    'health_latency_ms' => $answer->latencyMs,
                    'health_due_at' => $finished + $delay, 'health_revision' => $revision + 2,
                ]);
            }
            return $count;
        } finally {
            $lock->release();
        }
    }

    private function eligible(): Builder
    {
        return ExternalSubscriptionNode::where('selected', true)->where('present', true)->where('supported', true)
            ->whereHas('source', fn (Builder $query) => $query->where('enabled', true));
    }
}
