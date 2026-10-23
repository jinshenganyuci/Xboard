<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalSubscription;
use App\Services\ExternalSubscription\SyncService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExternalSubscriptionController extends Controller
{
    public function fetch()
    {
        $sources = ExternalSubscription::query()->withCount([
            'nodes',
            'nodes as available_nodes_count' => fn ($query) => $query->where('present', true)->where('supported', true),
            'nodes as selected_nodes_count' => fn ($query) => $query->where('selected', true)->where('present', true)->where('supported', true),
        ])->orderBy('id')->get();

        return response()->json(['data' => $sources->map(fn ($source) => $this->sourceData($source))]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:100'],
            'url' => ['nullable', 'string', 'max:4096', 'url:http,https'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        if (empty($data['id'])) {
            if (empty($data['url'])) {
                throw ValidationException::withMessages(['url' => '请输入第三方订阅地址。']);
            }
            $source = ExternalSubscription::create([
                'name' => $data['name'],
                'url' => $data['url'],
                'enabled' => false,
            ]);

            return response()->json(['data' => $this->sourceData($source)]);
        }

        $source = ExternalSubscription::findOrFail($data['id']);
        return $this->locked($source, function () use ($source, $data) {
            return DB::transaction(function () use ($source, $data) {
                $source->refresh();
                $source->name = $data['name'];
                if (array_key_exists('enabled', $data)) {
                    $source->enabled = $data['enabled'];
                }
                if (!empty($data['url']) && $data['url'] !== $source->url) {
                    $source->url = $data['url'];
                    // A replacement URL is a different source until synchronized and reviewed.
                    $source->enabled = false;
                    $source->last_synced_at = null;
                    $source->last_error = null;
                    $source->nodes()->update(['selected' => false, 'present' => false]);
                }
                $source->save();

                return response()->json(['data' => $this->sourceData($source)]);
            });
        });
    }

    public function drop(Request $request)
    {
        $source = $this->source($request);
        return $this->locked($source, function () use ($source) {
            DB::transaction(function () use ($source) {
                $source->nodes()->delete();
                $source->delete();
            });
            return response()->json(['data' => true]);
        });
    }

    public function sync(Request $request, SyncService $service)
    {
        $source = $this->source($request);
        try {
            $result = $service->sync($source);
        } catch (\Throwable $exception) {
            // Upstream HTTP errors can include its URL/token. Never send them to the browser.
            return response()->json(['message' => '同步失败，已保留上次成功的节点。请检查订阅地址或稍后重试。'], 422);
        }

        return response()->json(['data' => collect($result)->only([
            'total', 'supported', 'unsupported', 'added', 'updated', 'removed',
        ])->all()]);
    }

    public function nodes(Request $request)
    {
        $source = $this->source($request);
        $nodes = $source->nodes()->orderBy('sort_order')->orderBy('id')->get([
            'id', 'source_id', 'name', 'type', 'selected', 'present', 'supported', 'error', 'sort_order',
        ]);

        return response()->json(['data' => $nodes->map(fn ($node) => [
            'id' => $node->id,
            'name' => $node->name,
            'type' => $node->type,
            'selected' => (bool) $node->selected,
            'present' => (bool) $node->present,
            'supported' => (bool) $node->supported,
            'error' => $node->error,
            'sort_order' => $node->sort_order,
        ])]);
    }

    public function select(Request $request)
    {
        $source = $this->source($request);
        $data = $request->validate([
            'node_ids' => ['present', 'array', 'max:10000'],
            'node_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_map('intval', $data['node_ids']);

        return $this->locked($source, function () use ($source, $ids) {
            return DB::transaction(function () use ($source, $ids) {
                $eligible = $source->nodes()->whereIn('id', $ids)
                    ->where('present', true)->where('supported', true)->count();
                if ($eligible !== count($ids)) {
                    throw ValidationException::withMessages([
                        'node_ids' => '选中项包含其他订阅源、已失效或不支持的节点，请刷新后重试。',
                    ]);
                }

                $source->nodes()->update(['selected' => false]);
                $source->nodes()->whereIn('id', $ids)->update(['selected' => true]);

                return response()->json(['data' => ['selected_count' => count($ids)]]);
            });
        });
    }

    private function source(Request $request): ExternalSubscription
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        return ExternalSubscription::findOrFail($data['id']);
    }

    private function locked(ExternalSubscription $source, callable $callback)
    {
        try {
            return Cache::lock('external-subscription:' . $source->id, 120)->block(3, $callback);
        } catch (LockTimeoutException $exception) {
            return response()->json(['message' => '该订阅源正在同步，请稍后重试。'], 409);
        }
    }

    private function sourceData(ExternalSubscription $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'enabled' => (bool) $source->enabled,
            'url_configured' => true,
            'last_synced_at' => $source->last_synced_at,
            'last_error' => $source->last_error,
            'nodes_count' => (int) ($source->nodes_count ?? 0),
            'available_nodes_count' => (int) ($source->available_nodes_count ?? 0),
            'selected_nodes_count' => (int) ($source->selected_nodes_count ?? 0),
        ];
    }
}
