<?php

namespace Tests\Feature\ExternalSubscription;

use App\Http\Controllers\V2\Admin\ExternalSubscriptionController;
use App\Models\AdminAuditLog;
use App\Models\ExternalSubscription;
use App\Models\ExternalSubscriptionNode;
use App\Models\User;
use App\Services\ExternalSubscription\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function endpoint(string $action): string
    {
        $route = Route::getRoutes()->getByAction(ExternalSubscriptionController::class . '@fetch');
        return '/' . substr($route->uri(), 0, -strlen('fetch')) . $action;
    }

    private function user(bool $admin): User
    {
        return User::create([
            'email' => Str::random(12) . '@example.com',
            'password' => bcrypt('testing-password'),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'is_admin' => $admin,
        ]);
    }

    private function source(array $attributes = []): ExternalSubscription
    {
        return ExternalSubscription::create($attributes + [
            'name' => '测试订阅',
            'url' => 'https://example.com/subscribe?token=private-upstream-token',
            'enabled' => false,
        ]);
    }

    private function node(ExternalSubscription $source, array $attributes = []): ExternalSubscriptionNode
    {
        return $source->nodes()->create($attributes + [
            'fingerprint' => hash('sha256', Str::random(32)),
            'name' => '香港 01',
            'type' => 'trojan',
            'config' => ['password' => 'private-node-password', 'host' => 'example.com', 'port' => 443],
            'selected' => false,
            'present' => true,
            'supported' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_guest_and_non_admin_cannot_read_or_write_sources(): void
    {
        foreach ([false, true] as $authenticated) {
            if ($authenticated) {
                Sanctum::actingAs($this->user(false));
            }
            foreach (['fetch', 'nodes?id=1'] as $action) {
                $this->getJson($this->endpoint($action))->assertForbidden();
            }
            foreach (['save', 'drop', 'sync', 'select'] as $action) {
                $this->postJson($this->endpoint($action), ['id' => 1])->assertForbidden();
            }
        }
        $this->assertSame(0, ExternalSubscription::count());
    }

    public function test_admin_can_create_a_disabled_encrypted_source_without_leaking_its_url(): void
    {
        Sanctum::actingAs($this->user(true));
        $response = $this->postJson($this->endpoint('save'), [
            'name' => '主力订阅',
            'url' => 'https://example.com/subscribe?token=private-upstream-token',
            'enabled' => true,
        ])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonMissingPath('data.url');
        $this->assertStringNotContainsString('private-upstream-token', $response->getContent());
        $this->assertStringNotContainsString('private-upstream-token', DB::table('external_subscriptions')->value('url'));
        $this->assertSame('https://example.com/subscribe?token=private-upstream-token', ExternalSubscription::first()->url);

        $audit = AdminAuditLog::latest('id')->firstOrFail();
        $this->assertStringNotContainsString('private-upstream-token', $audit->request_data);
        $this->assertArrayNotHasKey('url', json_decode($audit->request_data, true));
    }

    public function test_source_and_node_lists_never_return_upstream_credentials(): void
    {
        Sanctum::actingAs($this->user(true));
        $source = $this->source(['enabled' => true]);
        $this->node($source, ['selected' => true]);
        $response = $this->getJson($this->endpoint('fetch'))->assertOk()
            ->assertJsonPath('data.0.selected_nodes_count', 1)
            ->assertJsonMissingPath('data.0.url');
        $this->assertStringNotContainsString('private-upstream-token', $response->getContent());

        $response = $this->getJson($this->endpoint('nodes?id=' . $source->id))->assertOk()
            ->assertJsonPath('data.0.name', '香港 01')
            ->assertJsonMissingPath('data.0.config');
        $this->assertStringNotContainsString('private-node-password', $response->getContent());
    }

    public function test_selection_rejects_foreign_missing_and_unsupported_nodes_without_changing_existing_selection(): void
    {
        Sanctum::actingAs($this->user(true));
        $source = $this->source();
        $selected = $this->node($source, ['selected' => true]);
        $foreign = $this->node($this->source());
        $unsupported = $this->node($source, ['supported' => false]);
        $removed = $this->node($source, ['present' => false]);

        foreach ([$foreign->id, $unsupported->id, $removed->id, 999999] as $invalid) {
            $this->postJson($this->endpoint('select'), ['id' => $source->id, 'node_ids' => [$invalid]])
                ->assertUnprocessable()->assertJsonValidationErrors('node_ids');
            $this->assertTrue($selected->fresh()->selected);
        }
    }

    public function test_selection_replaces_the_full_set_and_can_be_cleared(): void
    {
        Sanctum::actingAs($this->user(true));
        $source = $this->source();
        $old = $this->node($source, ['selected' => true]);
        $new = $this->node($source);

        $this->postJson($this->endpoint('select'), ['id' => $source->id, 'node_ids' => [$new->id]])
            ->assertOk()->assertJsonPath('data.selected_count', 1);
        $this->assertFalse($old->fresh()->selected);
        $this->assertTrue($new->fresh()->selected);

        $this->postJson($this->endpoint('select'), ['id' => $source->id, 'node_ids' => []])
            ->assertOk()->assertJsonPath('data.selected_count', 0);
        $this->assertFalse($new->fresh()->selected);
    }

    public function test_editing_name_preserves_url_but_replacing_url_disables_old_nodes(): void
    {
        Sanctum::actingAs($this->user(true));
        $source = $this->source(['enabled' => true]);
        $node = $this->node($source, ['selected' => true]);
        $this->postJson($this->endpoint('save'), ['id' => $source->id, 'name' => '改名', 'url' => ''])
            ->assertOk();
        $this->assertTrue($source->fresh()->enabled);
        $this->assertStringContainsString('private-upstream-token', $source->fresh()->url);

        $this->postJson($this->endpoint('save'), [
            'id' => $source->id, 'name' => '改源', 'url' => 'https://example.com/new-subscription', 'enabled' => true,
        ])->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertFalse($node->fresh()->selected);
        $this->assertFalse($node->fresh()->present);
    }

    public function test_sync_failure_does_not_expose_exception_url_or_remove_existing_nodes(): void
    {
        Sanctum::actingAs($this->user(true));
        $source = $this->source();
        $node = $this->node($source, ['selected' => true]);
        $this->mock(SyncService::class)->shouldReceive('sync')->once()
            ->andThrow(new RuntimeException('https://example.com?token=private-upstream-token'));
        $response = $this->postJson($this->endpoint('sync'), ['id' => $source->id])->assertUnprocessable();
        $this->assertStringNotContainsString('private-upstream-token', $response->getContent());
        $this->assertTrue($node->fresh()->selected);
    }

    public function test_deleting_a_source_removes_its_nodes(): void
    {
        Sanctum::actingAs($this->user(true));
        $source = $this->source();
        $node = $this->node($source);
        $this->postJson($this->endpoint('drop'), ['id' => $source->id])->assertOk();
        $this->assertNull(ExternalSubscription::find($source->id));
        $this->assertNull(ExternalSubscriptionNode::find($node->id));
    }
}
