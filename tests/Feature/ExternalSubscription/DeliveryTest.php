<?php

namespace Tests\Feature\ExternalSubscription;

use App\Models\ExternalSubscription;
use App\Models\ExternalSubscriptionNode;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('d', 32)));
        admin_setting(['show_info_to_server_enable' => 0, 'show_protocol_to_server_enable' => 0]);
    }

    public function test_membership_with_zero_or_exhausted_native_quota_receives_selected_nodes(): void
    {
        $this->externalNode('Selected node');
        foreach ([['transfer_enable' => 0], ['transfer_enable' => 10, 'u' => 10, 'd' => 10]] as $attributes) {
            $user = $this->user($attributes);
            $response = $this->get($this->subscribeUrl($user));
            $response->assertOk();
            $this->assertStringContainsString('upstream-password', base64_decode($response->getContent()));
            $this->assertStringNotContainsString('total=', $response->headers->get('subscription-userinfo', ''));
            $this->assertStringContainsString('expire=', $response->headers->get('subscription-userinfo', ''));
        }
    }

    public function test_missing_plan_expired_or_banned_users_never_receive_external_nodes(): void
    {
        $this->externalNode('Private node');
        foreach ([['plan_id' => null], ['expired_at' => time() - 1], ['banned' => true]] as $attributes) {
            $user = $this->user($attributes);
            $response = $this->get($this->subscribeUrl($user));
            $this->assertStringNotContainsString('upstream-password', base64_decode($response->getContent()) ?: '');
            $this->assertStringNotContainsString('upstream-password', $response->getContent());
        }
        $this->get('/api/v1/client/subscribe?token=unknown-token')->assertStatus(403);
    }

    public function test_lifetime_membership_is_allowed_and_expiry_takes_effect_on_next_request(): void
    {
        $this->externalNode('Lifetime node');
        $user = $this->user(['expired_at' => null]);
        $this->get($this->subscribeUrl($user))->assertOk();
        $user->update(['expired_at' => time() - 10]);
        $this->get($this->subscribeUrl($user))->assertStatus(403);
    }

    public function test_only_explicitly_selected_supported_present_nodes_from_enabled_sources_are_delivered(): void
    {
        $selected = $this->externalNode('Selected node');
        $this->externalNode('Not selected', ['selected' => false]);
        $this->externalNode('Removed node', ['present' => false]);
        $this->externalNode('Unsupported node', ['supported' => false]);
        $disabled = $this->externalNode('Disabled source');
        $disabled->source->update(['enabled' => false]);
        $user = $this->user();
        $response = $this->get($this->subscribeUrl($user))->assertOk();
        $body = rawurldecode(base64_decode($response->getContent()));
        $this->assertStringContainsString('Selected node', $body);
        foreach (['Not selected', 'Removed node', 'Unsupported node', 'Disabled source'] as $name) {
            $this->assertStringNotContainsString($name, $body);
        }
        $selected->update(['selected' => false]);
        $this->get($this->subscribeUrl($user))->assertStatus(403);
    }

    public function test_old_and_short_subscription_urls_keep_upstream_credentials_for_different_users(): void
    {
        $this->externalNode('Shared upstream');
        $first = $this->user();
        $second = $this->user();
        foreach ([$this->subscribeUrl($first), '/s/' . $second->token . '?flag=general'] as $url) {
            $response = $this->get($url)->assertOk();
            $body = base64_decode($response->getContent());
            $this->assertStringContainsString('upstream-password', $body);
            $this->assertStringNotContainsString($first->uuid, $body);
            $this->assertStringNotContainsString($second->uuid, $body);
        }
    }

    public function test_zero_quota_does_not_unlock_native_nodes_and_normal_native_credentials_are_preserved(): void
    {
        $this->externalNode('External');
        Server::create([
            'name' => 'Native node', 'type' => 'trojan', 'host' => 'native.example.com',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'show' => true,
            'group_ids' => ['1'], 'tags' => [], 'protocol_settings' => [
                'network' => 'tcp', 'tls' => 1,
                'tls_settings' => ['server_name' => 'native.example.com', 'allow_insecure' => false],
            ],
        ]);
        $user = $this->user(['group_id' => 1]);
        $zero = base64_decode($this->get($this->subscribeUrl($user))->assertOk()->getContent());
        $this->assertStringNotContainsString('native.example.com', $zero);
        $user->update(['transfer_enable' => 100]);
        $response = $this->get($this->subscribeUrl($user))->assertOk();
        $mixed = base64_decode($response->getContent());
        $this->assertStringContainsString('native.example.com', $mixed);
        $this->assertStringContainsString($user->uuid, $mixed);
        $this->assertStringContainsString('upstream-password', $mixed);
        $this->assertStringContainsString('total=100', $response->headers->get('subscription-userinfo'));
    }

    public function test_dashboard_lists_only_safe_external_fields_and_selection_invalidates_etag(): void
    {
        $node = $this->externalNode('Dashboard node');
        $user = $this->user();
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/user/server/fetch')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.external', true);
        $this->assertNull($response->json('data.0.is_online'));
        foreach (['password', 'config', 'host', 'url', 'source_id'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data.0'));
        }
        $this->assertStringNotContainsString('upstream-password', $response->getContent());
        $etag = $response->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/user/server/fetch')->assertStatus(304);
        $node->update(['selected' => false]);
        $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/user/server/fetch')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_external_names_cannot_collide_with_native_names_after_merging(): void
    {
        $node = $this->externalNode('Shared name');
        $name = 'Shared name〔外部' . $node->id . '〕';
        foreach ([$name, $name . ' #2'] as $nativeName) {
            Server::create([
                'name' => $nativeName, 'type' => 'trojan', 'host' => 'native.example.com',
                'port' => 443, 'server_port' => 443, 'rate' => 1, 'show' => true,
                'group_ids' => ['1'], 'tags' => [], 'protocol_settings' => [
                    'network' => 'tcp', 'tls' => 1,
                    'tls_settings' => ['server_name' => 'native.example.com', 'allow_insecure' => false],
                ],
            ]);
        }
        $user = $this->user(['transfer_enable' => 100]);
        $response = $this->get($this->subscribeUrl($user))->assertOk();
        $lines = array_filter(explode("\n", trim(base64_decode($response->getContent()))));
        $names = array_map(fn ($line) => rawurldecode(parse_url(trim($line), PHP_URL_FRAGMENT)), $lines);
        $this->assertSame([$name, $name . ' #2', $name . ' #3'], array_values($names));
        $this->assertStringContainsString('upstream-password', base64_decode($response->getContent()));
    }

    private function user(array $attributes = []): User
    {
        $suffix = bin2hex(random_bytes(8));
        return User::create(array_merge([
            'email' => $suffix . '@example.com', 'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'token' => 'token-' . $suffix, 'uuid' => 'local-uuid-' . $suffix,
            'plan_id' => 1, 'group_id' => 1, 'expired_at' => time() + 3600,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'banned' => false,
        ], $attributes));
    }

    private function externalNode(string $name, array $attributes = []): ExternalSubscriptionNode
    {
        $source = ExternalSubscription::create([
            'name' => 'Test source', 'url' => 'https://upstream.example.com/subscribe?token=private-token', 'enabled' => true,
        ]);
        return ExternalSubscriptionNode::create(array_merge([
            'source_id' => $source->id, 'fingerprint' => hash('sha256', $name), 'name' => $name,
            'type' => 'trojan', 'selected' => true, 'present' => true, 'supported' => true,
            'sort_order' => 0, 'config' => [
                'name' => $name, 'type' => 'trojan', 'host' => 'upstream.example.com', 'port' => 443,
                'password' => 'upstream-password', 'external' => true, 'tags' => [],
                'protocol_settings' => ['network' => 'tcp', 'tls' => 1, 'utls' => ['enabled' => false],
                    'tls_settings' => ['server_name' => 'upstream.example.com', 'allow_insecure' => false]],
            ],
        ], $attributes));
    }

    private function subscribeUrl(User $user): string
    {
        return '/api/v1/client/subscribe?token=' . $user->token . '&flag=general';
    }
}
