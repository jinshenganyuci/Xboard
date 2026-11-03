<?php

namespace App\Services\ExternalSubscription;

use App\Models\ExternalSubscription;
use App\Models\ExternalSubscriptionNode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class NodeProvider
{
    public function canSubscribe(User $user): bool
    {
        return $user->isActive() && ExternalSubscription::where('enabled', true)->exists();
    }

    private function query(): Builder
    {
        return ExternalSubscriptionNode::query()
            ->whereHas('source', fn (Builder $query) => $query->where('enabled', true))
            ->where('selected', true)->where('present', true)->where('supported', true)
            ->orderBy('source_id')->orderBy('sort_order')->orderBy('id');
    }

    public function forUser(User $user): array
    {
        if (!$user->isActive()) {
            return [];
        }

        return $this->query()->get()->map(function (ExternalSubscriptionNode $node) {
            $config = $node->config;
            // Preserve upstream display names; subscription merging resolves collisions.
            $config['id'] = -$node->id;
            $config['name'] = $node->name;
            $config['external'] = true;
            return $config;
        })->all();
    }

    public function selectedCount(): int
    {
        return $this->query()->count();
    }

    /** Presentation fields only. Reading this list never connects to an upstream node. */
    public function dashboardForUser(User $user): array
    {
        if (!$user->isActive()) {
            return [];
        }

        $nodes = $this->query()->with('source:id,enabled')->get();
        if ($nodes->isEmpty()) {
            return [];
        }
        $statuses = app(HealthService::class)->statuses($nodes);

        return $nodes->map(static function (ExternalSubscriptionNode $node) use ($statuses) {
            $status = $statuses[$node->id] ?? [];
            $config = $node->config;
            return [
                'id' => -$node->id,
                'type' => $node->type,
                'version' => data_get($config, 'protocol_settings.version'),
                'name' => $node->name,
                'rate' => null,
                'tags' => $config['tags'] ?? [],
                'health_status' => $status['health_status'] ?? 'unknown',
                'is_online' => $status['is_online'] ?? null,
                'last_check_at' => $status['last_check_at'] ?? null,
                'cache_key' => 'external_' . (-$node->id),
                'external' => true,
            ];
        })->all();
    }
}
