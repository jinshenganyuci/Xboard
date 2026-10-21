<?php

namespace App\Services\ExternalSubscription;

use App\Models\ExternalSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncService
{
    public function __construct(private Fetcher $fetcher, private Parser $parser)
    {
    }

    public function sync(ExternalSubscription $source): array
    {
        $lock = Cache::lock('external-subscription:' . $source->id, 120);
        if (!$lock->get()) {
            throw new RuntimeException('该订阅正在同步或编辑，请稍后重试。');
        }
        $snapshot = null;
        try {
            $snapshot = $source->fresh();
            if (!$snapshot) {
                throw new RuntimeException();
            }
            $nodes = $this->parser->parse($this->fetcher->fetch($snapshot->url));
            return DB::transaction(function () use ($snapshot, $nodes) {
                $current = ExternalSubscription::whereKey($snapshot->id)->lockForUpdate()->first();
                if (!$current || $current->getRawOriginal('url') !== $snapshot->getRawOriginal('url') || $current->getRawOriginal('updated_at') !== $snapshot->getRawOriginal('updated_at')) {
                    throw new RuntimeException();
                }
                $existing = $current->nodes()->get()->keyBy('fingerprint');
                $counts = ['total' => count($nodes), 'supported' => 0, 'unsupported' => 0, 'added' => 0, 'updated' => 0, 'removed' => 0];
                $seen = [];
                foreach ($nodes as $position => $data) {
                    $seen[] = $data['fingerprint'];
                    $node = $existing->get($data['fingerprint']);
                    if (!$node) {
                        $node = $current->nodes()->make(['selected' => false]);
                        $counts['added']++;
                    } else {
                        $counts['updated']++;
                    }
                    $node->fill($data + ['present' => true, 'sort_order' => $position]);
                    $node->save();
                    $counts[$data['supported'] ? 'supported' : 'unsupported']++;
                }
                $counts['removed'] = $current->nodes()->where('present', true)->whereNotIn('fingerprint', $seen)->update(['present' => false]);
                $current->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
                return $counts;
            });
        } catch (Throwable $e) {
            $message = '同步失败，请检查订阅地址、网络和内容格式；上次成功结果已保留。';
            if ($snapshot) {
                ExternalSubscription::whereKey($snapshot->id)
                    ->where('url', $snapshot->getRawOriginal('url'))
                    ->where('updated_at', $snapshot->getRawOriginal('updated_at'))
                    ->update(['last_error' => $message]);
            }
            throw new RuntimeException($message);
        } finally {
            $lock->release();
        }
    }
}
