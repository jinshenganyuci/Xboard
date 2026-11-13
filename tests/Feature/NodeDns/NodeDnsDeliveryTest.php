<?php

namespace Tests\Feature\NodeDns;

use App\Models\Server;
use App\Models\User;
use App\Services\GlobalDnsService;
use App\Services\ServerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class NodeDnsDeliveryTest extends TestCase
{
    private const FIRST_SECRET = 'synthetic-global-dns-secret-first';
    private const SECOND_SECRET = 'synthetic-global-dns-secret-second';
    private const NODE_TOKEN = 'synthetic-node-api-token';

    protected function setUp(): void
    {
        parent::setUp();
        // Real commits are required for notification tests. Recreate this isolated
        // test database using up() only: legacy down() migrations are not SQLite-safe.
        // Do not wrap tests in RefreshDatabase's outer transaction or run rollback.
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        // A following RefreshDatabase test must not reuse this class's committed fixtures.
        RefreshDatabaseState::$migrated = false;
        admin_setting([
            'server_token' => self::NODE_TOKEN,
            'show_info_to_server_enable' => 0,
            'show_protocol_to_server_enable' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        // Keep the manager alive until framework teardown has run its callbacks,
        // then release every PDO. Otherwise SQLite WAL handles from the previous
        // application can lock the next test's migrate:fresh connection.
        $database = $this->app?->make('db');
        try {
            parent::tearDown();
        } finally {
            if ($database) {
                foreach ($database->getConnections() as $name => $connection) {
                    try {
                        if ($connection->transactionLevel() > 0) {
                            $connection->rollBack(0);
                        }
                    } finally {
                        $database->purge($name);
                    }
                }
            }
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_authenticated_http_delivery_resolves_global_config_and_rotation_changes_only_bound_etags(): void
    {
        $this->saveGlobal();
        $bound = $this->node();
        $independentConfig = [
            'cert_mode' => 'dns', 'domain' => 'independent.example.com',
            'email' => 'independent@example.com', 'dns_provider' => 'cloudflare',
            'dns_env' => ['CF_API_TOKEN' => 'synthetic-independent-credential'],
        ];
        $independent = $this->node(['cert_config' => $independentConfig]);
        $storedIndependent = $independent->getRawOriginal('cert_config');
        $storedBound = $bound->getRawOriginal('cert_config');

        $before = $this->getJson($this->nodeUrl($bound))->assertOk();
        $before->assertJsonPath('cert_config', [
            'cert_mode' => 'dns', 'domain' => 'node.example.com',
            'email' => 'acme@example.com', 'dns_provider' => 'cloudflare',
            'dns_env' => ['CF_API_TOKEN' => self::FIRST_SECRET],
        ]);
        $this->assertArrayNotHasKey('use_global_dns', $before->json('cert_config'));
        $oldEtag = $before->headers->get('ETag');
        $this->assertNotEmpty($oldEtag);
        $this->getJson($this->nodeUrl($bound), ['If-None-Match' => $oldEtag])->assertStatus(304);
        $independentEtag = $this->getJson($this->nodeUrl($independent))->assertOk()->headers->get('ETag');

        $this->saveGlobal(1, self::SECOND_SECRET);
        $after = $this->getJson($this->nodeUrl($bound), ['If-None-Match' => $oldEtag])->assertOk();
        $after->assertJsonPath('cert_config.dns_env.CF_API_TOKEN', self::SECOND_SECRET);
        $this->assertNotSame($oldEtag, $after->headers->get('ETag'));
        $this->getJson($this->nodeUrl($independent), ['If-None-Match' => $independentEtag])->assertStatus(304);
        $this->assertSame($storedIndependent, $independent->fresh()->getRawOriginal('cert_config'));
        $this->assertSame($storedBound, $bound->fresh()->getRawOriginal('cert_config'));

        // A long-lived node model and a fresh service both read the persisted rotation.
        $this->assertSame(self::SECOND_SECRET, ServerService::buildNodeConfig($bound)['cert_config']['dns_env']['CF_API_TOKEN']);
        Cache::flush();
        $this->assertSame(self::SECOND_SECRET, (new GlobalDnsService())->resolveNode($bound->cert_config)['dns_env']['CF_API_TOKEN']);
    }

    public function test_unauthenticated_node_request_never_receives_dns_credentials(): void
    {
        $this->saveGlobal();
        $node = $this->node();
        foreach (['', 'incorrect-token'] as $token) {
            $response = $this->getJson('/api/v1/server/UniProxy/config?' . http_build_query([
                'node_id' => $node->id, 'node_type' => 'anytls', 'token' => $token,
            ]))->assertStatus(422);
            $this->assertStringNotContainsString(self::FIRST_SECRET, $response->getContent());
            $this->assertStringNotContainsString('CF_API_TOKEN', $response->getContent());
        }
    }

    public function test_rotation_notifies_only_online_inheriting_nodes_after_the_outer_transaction_commits(): void
    {
        $this->saveGlobal();
        $bound = $this->node();
        $offline = $this->node();
        $independent = $this->node(['cert_config' => ['cert_mode' => 'none']]);
        Cache::put("node_ws_alive:{$bound->id}", true, 60);
        Cache::put("node_ws_alive:{$independent->id}", true, 60);
        $published = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$published) {
            $published[] = ['channel' => $channel, 'payload' => json_decode($payload, true), 'transaction' => DB::transactionLevel()];
            return 1;
        });

        DB::beginTransaction();
        try {
            $this->saveGlobal(1, self::SECOND_SECRET);
            $this->assertSame([], $published);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertCount(1, $published);
        $this->assertSame('node:push', $published[0]['channel']);
        $this->assertSame(0, $published[0]['transaction']);
        $this->assertSame($bound->id, $published[0]['payload']['node_id']);
        $this->assertSame('sync.config', $published[0]['payload']['event']);
        $this->assertSame(self::SECOND_SECRET, $published[0]['payload']['data']['config']['cert_config']['dns_env']['CF_API_TOKEN']);
        $this->assertArrayNotHasKey('use_global_dns', $published[0]['payload']['data']['config']['cert_config']);
        $this->assertSame(self::SECOND_SECRET, ServerService::buildNodeConfig($offline)['cert_config']['dns_env']['CF_API_TOKEN']);
    }

    public function test_rolled_back_rotation_does_not_notify_nodes_or_replace_persisted_credentials(): void
    {
        $this->saveGlobal();
        $bound = $this->node();
        Cache::put("node_ws_alive:{$bound->id}", true, 60);
        $published = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$published) {
            $published[] = $payload;
            return 1;
        });

        DB::beginTransaction();
        try {
            $this->saveGlobal(1, self::SECOND_SECRET);
        } finally {
            DB::rollBack();
        }

        $this->assertSame([], $published);
        $this->assertSame(1, (new GlobalDnsService())->metadata()['revision']);
        $this->assertSame(self::FIRST_SECRET, ServerService::buildNodeConfig($bound)['cert_config']['dns_env']['CF_API_TOKEN']);
    }

    public function test_failed_websocket_publish_logs_no_credentials_and_http_pull_still_obtains_rotation(): void
    {
        $this->saveGlobal();
        $bound = $this->node();
        Cache::put("node_ws_alive:{$bound->id}", true, 60);
        Redis::shouldReceive('publish')->andThrow(new RuntimeException('simulated Redis error with payload ' . self::SECOND_SECRET));
        $warnings = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$warnings) {
            $warnings[] = [$message, $context];
        });

        $this->saveGlobal(1, self::SECOND_SECRET);

        $this->assertNotEmpty($warnings);
        $this->assertStringNotContainsString(self::SECOND_SECRET, json_encode($warnings));
        $this->getJson($this->nodeUrl($bound))->assertOk()
            ->assertJsonPath('cert_config.dns_env.CF_API_TOKEN', self::SECOND_SECRET);
    }

    public function test_user_dashboard_and_subscription_never_contain_global_dns_credentials(): void
    {
        $this->saveGlobal();
        $this->node();
        $user = User::create([
            'email' => Str::random(12) . '@example.com', 'password' => bcrypt('synthetic-password'),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => false,
            'group_id' => 1, 'plan_id' => 1, 'expired_at' => time() + 86400,
            'transfer_enable' => 1073741824, 'u' => 0, 'd' => 0, 'banned' => false,
        ]);
        Sanctum::actingAs($user);
        $dashboard = $this->getJson('/api/v1/user/server/fetch')->assertOk()->assertJsonCount(1, 'data');
        $subscription = $this->get('/api/v1/client/subscribe?token=' . $user->token . '&flag=general')->assertOk();
        $decoded = base64_decode($subscription->getContent(), true);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('anytls://', $decoded);
        $this->assertStringContainsString($user->uuid, $decoded);
        foreach ([$dashboard->getContent(), $subscription->getContent(), $decoded] as $body) {
            foreach ([self::FIRST_SECRET, 'CF_API_TOKEN', 'dns_env', 'acme@example.com', 'cert_config', 'use_global_dns'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }
        }
    }

    private function saveGlobal(int $revision = 0, string $secret = self::FIRST_SECRET): array
    {
        return (new GlobalDnsService())->save([
            'revision' => $revision, 'dns_provider' => 'cloudflare', 'email' => 'acme@example.com',
            'dns_env' => ['CF_API_TOKEN' => $secret],
        ]);
    }

    private function node(array $overrides = []): Server
    {
        return Server::create($overrides + [
            'name' => 'Synthetic AnyTLS node', 'type' => 'anytls', 'host' => 'node.example.com',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'show' => true, 'enabled' => true,
            'group_ids' => ['1'], 'tags' => [], 'route_ids' => [],
            'protocol_settings' => [
                'tls' => ['server_name' => 'node.example.com', 'allow_insecure' => false],
                'padding_scheme' => [],
            ],
            'cert_config' => ['cert_mode' => 'dns', 'domain' => 'node.example.com', 'use_global_dns' => true],
        ]);
    }

    private function nodeUrl(Server $node): string
    {
        return '/api/v1/server/UniProxy/config?' . http_build_query([
            'node_id' => $node->id, 'node_type' => 'anytls', 'token' => self::NODE_TOKEN,
        ]);
    }
}
