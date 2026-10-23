<?php

namespace App\Services\ExternalSubscription;

use App\Models\ExternalSubscriptionNode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class NodeProvider
{
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
}
