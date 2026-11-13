<?php

namespace Tests\Feature\NodeDns;

use App\Http\Controllers\V2\Admin\GlobalDnsController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\AdminAuditLog;
use App\Models\GlobalDnsSetting;
use App\Models\Server;
use App\Models\User;
use App\Services\GlobalDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalDnsAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'synthetic-global-dns-token=never-a-real-secret';

    private function endpoint(string $action = 'fetch', string $controller = GlobalDnsController::class): string
    {
        return '/' . Route::getRoutes()->getByAction($controller . '@' . $action)->uri();
    }

    private function login(bool $admin = true): void
    {
        Sanctum::actingAs(User::create([
            'email' => Str::random(12) . '@example.com', 'password' => bcrypt('testing-password'),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin,
        ]));
    }

    private function settings(array $overrides = []): array
    {
        return $overrides + [
            'revision' => 0, 'dns_provider' => 'cloudflare', 'email' => 'certs@example.com',
            'dns_env' => ['CF_API_TOKEN' => self::SECRET],
        ];
    }

    private function node(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'anytls', 'name' => 'DNS test node', 'host' => 'node.example.com',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => [],
            'protocol_settings' => ['tls' => ['server_name' => 'node.example.com'], 'padding_scheme' => []],
        ];
    }

    private function globalCertificate(array $overrides = []): array
    {
        return array_replace(['cert_mode' => 'dns', 'domain' => 'node.example.com', 'use_global_dns' => true], $overrides);
    }

    public function test_only_admin_can_read_and_save_global_dns(): void
    {
        foreach ([false, true] as $authenticated) {
            if ($authenticated) {
                $this->login(false);
            }
            $this->getJson($this->endpoint())->assertForbidden();
            $this->postJson($this->endpoint('save'), $this->settings())->assertForbidden();
        }
        $this->assertSame(0, GlobalDnsSetting::findOrFail(1)->revision);
    }

    public function test_fresh_upgrade_has_no_credentials_and_metadata_is_not_cacheable(): void
    {
        $this->login();
        $response = $this->getJson($this->endpoint())->assertOk()->assertExactJson(['data' => [
            'configured' => false, 'dns_provider' => 'cloudflare', 'email' => '',
            'has_dns_env' => false, 'updated_at' => null, 'revision' => 0,
        ]]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_secret_is_encrypted_and_never_echoed_or_written_to_audit(): void
    {
        $this->login();
        $save = $this->postJson($this->endpoint('save') . '?dns_env=' . urlencode(self::SECRET), $this->settings())
            ->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.revision', 1)
            ->assertJsonMissingPath('data.dns_env');
        $fetch = $this->getJson($this->endpoint())->assertOk()->assertJsonMissingPath('data.dns_env');
        $this->assertSame(['CF_API_TOKEN' => self::SECRET], GlobalDnsSetting::findOrFail(1)->dns_env);
        foreach ([
            $save->getContent(), $fetch->getContent(), GlobalDnsSetting::findOrFail(1)->toJson(),
            DB::table('global_dns_settings')->value('dns_env'), AdminAuditLog::get()->toJson(),
        ] as $serialized) {
            $this->assertStringNotContainsString(self::SECRET, $serialized);
        }
        $audit = AdminAuditLog::latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['dns_provider', 'email', 'revision'], array_keys(json_decode($audit->request_data, true)));
        $this->assertStringNotContainsString('?', $audit->uri);
    }

    public function test_initial_credentials_are_required_but_empty_update_preserves_them(): void
    {
        $this->login();
        $this->postJson($this->endpoint('save'), $this->settings(['dns_env' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('dns_env');
        $this->postJson($this->endpoint('save'), $this->settings())->assertOk();
        foreach ([[], null, 'missing'] as $index => $value) {
            $data = $this->settings(['revision' => $index + 1, 'dns_env' => $value, 'email' => 'rotated@example.com']);
            if ($value === 'missing') {
                unset($data['dns_env']);
            }
            $this->postJson($this->endpoint('save'), $data)->assertOk()->assertJsonPath('data.revision', $index + 2);
            $this->assertSame(['CF_API_TOKEN' => self::SECRET], GlobalDnsSetting::findOrFail(1)->dns_env);
        }
    }

    public function test_provider_change_requires_new_credentials_and_stale_writes_are_rejected(): void
    {
        $this->login();
        $this->postJson($this->endpoint('save'), $this->settings())->assertOk();
        $this->postJson($this->endpoint('save'), $this->settings(['revision' => 1, 'dns_provider' => 'route53', 'dns_env' => []]))
            ->assertUnprocessable();
        $this->postJson($this->endpoint('save'), $this->settings(['dns_env' => ['CF_API_TOKEN' => 'stale-token']]))
            ->assertStatus(409);
        $this->assertSame(['CF_API_TOKEN' => self::SECRET], GlobalDnsSetting::findOrFail(1)->dns_env);
        $this->postJson($this->endpoint('save'), $this->settings([
            'revision' => 1, 'dns_provider' => 'route53', 'dns_env' => ['AWS_ACCESS_KEY_ID' => 'synthetic-new-token'],
        ]))->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.dns_provider', 'route53');
        $this->assertSame(['AWS_ACCESS_KEY_ID' => 'synthetic-new-token'], GlobalDnsSetting::findOrFail(1)->dns_env);
        $this->assertSame(1, GlobalDnsSetting::count());
    }

    public function test_invalid_credentials_and_metadata_are_rejected_without_echoing_input(): void
    {
        $this->login();
        $invalid = [
            ['revision' => null], ['dns_provider' => 'Cloudflare'], ['email' => 'not-an-email'],
            ['dns_env' => 'not-an-object'], ['dns_env' => ['invalid secret key' => self::SECRET]],
            ['dns_env' => [self::SECRET]], ['dns_env' => ['CF_API_TOKEN' => ['nested' => self::SECRET]]],
            ['dns_env' => ['CF_API_TOKEN' => '   ']], ['dns_env' => ['CF_API_TOKEN' => self::SECRET . "\nsecond-line"]],
            ['dns_env' => ['CF_API_TOKEN' => str_repeat('x', 4097)]],
            ['dns_env' => array_fill_keys(array_map(fn ($i) => 'KEY_' . $i, range(1, 65)), self::SECRET)],
            ['dns_env' => array_fill_keys(['ONE', 'TWO', 'THREE', 'FOUR', 'FIVE'], str_repeat('x', 4096))],
        ];
        foreach ($invalid as $override) {
            $response = $this->postJson($this->endpoint('save'), $this->settings($override))->assertUnprocessable();
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        }
        $this->assertSame(0, GlobalDnsSetting::findOrFail(1)->revision);
    }

    public function test_existing_independent_nodes_are_not_changed_by_setup_or_rotation(): void
    {
        $independent = ['cert_mode' => 'dns', 'domain' => 'old.example.com', 'email' => 'old@example.com',
            'dns_provider' => 'cloudflare', 'dns_env' => ['CF_API_TOKEN' => 'synthetic-old-independent-token']];
        $node = Server::create($this->node(['cert_config' => $independent]));
        $original = DB::table('v2_server')->where('id', $node->id)->value('cert_config');
        $this->login();
        $this->postJson($this->endpoint('save'), $this->settings())->assertOk();
        $this->postJson($this->endpoint('save'), $this->settings(['revision' => 1, 'dns_env' => ['CF_API_TOKEN' => 'rotation']]))->assertOk();
        $this->assertSame($original, DB::table('v2_server')->where('id', $node->id)->value('cert_config'));
        $this->postJson($this->endpoint('save', ManageController::class), $this->node(['id' => $node->id, 'cert_config' => $independent]))->assertOk();
        $this->assertSame($independent, $node->fresh()->cert_config);
    }

    public function test_http_preserves_global_credential_bytes_and_rejects_edge_newlines(): void
    {
        $this->login();
        foreach (["\n" . self::SECRET, self::SECRET . "\n", self::SECRET . "\r\n"] as $value) {
            $response = $this->postJson($this->endpoint('save'), $this->settings(['dns_env' => ['CF_API_TOKEN' => $value]]))
                ->assertUnprocessable()->assertJsonValidationErrors('dns_env');
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
            $this->assertSame(0, GlobalDnsSetting::findOrFail(1)->revision);
        }

        $padded = '  ' . self::SECRET . '  ';
        $this->postJson($this->endpoint('save'), $this->settings(['dns_env' => ['CF_API_TOKEN' => $padded]]))->assertOk();
        $this->assertSame($padded, GlobalDnsSetting::findOrFail(1)->dns_env['CF_API_TOKEN']);

        // The middleware exception is limited to the new top-level global field.
        $this->postJson($this->endpoint('save', ManageController::class), $this->node(['cert_config' => [
            'cert_mode' => 'dns', 'domain' => 'node.example.com', 'email' => 'own@example.com',
            'dns_provider' => 'cloudflare', 'dns_env' => ['CF_API_TOKEN' => $padded],
        ]]))->assertOk();
        $this->assertSame(self::SECRET, Server::firstOrFail()->cert_config['dns_env']['CF_API_TOKEN']);
    }

    public function test_inherited_node_stores_only_reference_and_copy_preserves_it(): void
    {
        $this->login();
        $this->postJson($this->endpoint('save'), $this->settings())->assertOk();
        $config = $this->globalCertificate(['dns_env' => ['CF_API_TOKEN' => 'discard-local-copy'],
            'dns_provider' => 'discard-provider', 'email' => 'discard@example.com']);
        $this->postJson($this->endpoint('save', ManageController::class) . '?token=' . self::SECRET,
            $this->node(['cert_config' => $config]))->assertOk();
        $node = Server::firstOrFail();
        $this->assertSame($this->globalCertificate(), $node->cert_config);
        $listing = $this->getJson($this->endpoint('getNodes', ManageController::class))->assertOk();
        foreach ([$listing->getContent(), AdminAuditLog::get()->toJson(), $node->toJson()] as $content) {
            $this->assertStringNotContainsString(self::SECRET, $content);
            $this->assertStringNotContainsString('discard-local-copy', $content);
        }
        $this->postJson($this->endpoint('copy', ManageController::class), ['id' => $node->id])->assertOk();
        $copy = Server::where('id', '!=', $node->id)->firstOrFail();
        $this->assertSame($node->cert_config, $copy->cert_config);
    }

    public function test_node_marker_validation_does_not_remove_unrelated_certificate_fields(): void
    {
        $this->login();
        $certificate = ['cert_mode' => 'dns', 'domain' => 'independent.example.com',
            'email' => 'own@example.com', 'dns_provider' => 'cloudflare',
            'dns_env' => ['CF_API_TOKEN' => 'synthetic-independent-token'], 'http_port' => 8080];
        foreach ([null, 'true', 'false', 2, []] as $invalid) {
            $this->postJson($this->endpoint('save', ManageController::class), $this->node([
                'cert_config' => $certificate + ['use_global_dns' => $invalid],
            ]))->assertUnprocessable()->assertJsonValidationErrors('cert_config');
        }
        $this->assertSame(0, Server::count());
        $this->postJson($this->endpoint('save', ManageController::class), $this->node([
            'cert_config' => $certificate + ['use_global_dns' => false],
        ]))->assertOk();
        $this->assertSame($certificate, Server::firstOrFail()->cert_config);
    }

    public function test_old_ui_missing_marker_does_not_disconnect_an_inherited_node(): void
    {
        app(GlobalDnsService::class)->save($this->settings());
        $node = Server::create($this->node(['cert_config' => $this->globalCertificate()]));
        $this->login();
        $this->postJson($this->endpoint('save', ManageController::class), $this->node([
            'id' => $node->id, 'cert_config' => ['cert_mode' => 'dns', 'domain' => 'changed.example.com'],
        ]))->assertOk();
        $this->assertSame($this->globalCertificate(['domain' => 'changed.example.com']), $node->fresh()->cert_config);
        $this->postJson($this->endpoint('save', ManageController::class), $this->node([
            'id' => $node->id, 'cert_config' => ['domain' => 'partial.example.com'],
        ]))->assertOk();
        $this->assertSame($this->globalCertificate(['domain' => 'partial.example.com']), $node->fresh()->cert_config);
        foreach ([null, []] as $empty) {
            $this->postJson($this->endpoint('save', ManageController::class), $this->node(['id' => $node->id, 'cert_config' => $empty]))->assertOk();
            $this->assertTrue($node->fresh()->cert_config['use_global_dns']);
        }
        $independent = ['cert_mode' => 'dns', 'domain' => 'own.example.com', 'email' => 'own@example.com',
            'dns_provider' => 'cloudflare', 'dns_env' => ['CF_API_TOKEN' => 'synthetic-own-token']];
        $this->postJson($this->endpoint('save', ManageController::class), $this->node([
            'id' => $node->id, 'cert_config' => $independent + ['use_global_dns' => false],
        ]))->assertOk();
        $this->assertSame($independent, $node->fresh()->cert_config);
    }

    public function test_global_mode_requires_settings_and_a_valid_per_node_domain(): void
    {
        $this->login();
        $this->postJson($this->endpoint('save', ManageController::class), $this->node(['cert_config' => $this->globalCertificate()]))
            ->assertUnprocessable();
        app(GlobalDnsService::class)->save($this->settings());
        foreach (['127.0.0.1', 'https://node.example.com', 'node', '', 'bad..example.com'] as $domain) {
            $this->postJson($this->endpoint('save', ManageController::class), $this->node(['cert_config' => $this->globalCertificate(['domain' => $domain])]))
                ->assertUnprocessable();
        }
        $this->postJson($this->endpoint('save', ManageController::class), $this->node(['cert_config' => $this->globalCertificate(['cert_mode' => 'http'])]))
            ->assertUnprocessable();
        foreach (['one.example.com', '*.two.example.com'] as $domain) {
            $this->postJson($this->endpoint('save', ManageController::class), $this->node(['cert_config' => $this->globalCertificate(['domain' => $domain])]))
                ->assertOk();
        }
        $this->assertSame(['one.example.com', '*.two.example.com'], Server::orderBy('id')->get()->pluck('cert_config.domain')->all());
    }

    public function test_disabling_inheritance_requires_valid_independent_dns_credentials(): void
    {
        app(GlobalDnsService::class)->save($this->settings());
        $node = Server::create($this->node(['cert_config' => $this->globalCertificate()]));
        $this->login();
        $independent = ['cert_mode' => 'dns', 'domain' => 'node.example.com', 'use_global_dns' => false,
            'dns_provider' => 'cloudflare', 'email' => 'own@example.com', 'dns_env' => ['CF_API_TOKEN' => self::SECRET]];
        foreach ([
            ['dns_provider' => ''], ['dns_provider' => 'Invalid Provider'], ['email' => ''], ['email' => 'not-an-email'],
            ['dns_env' => []], ['dns_env' => null], ['dns_env' => ['bad key' => self::SECRET]],
            ['dns_env' => ['CF_API_TOKEN' => self::SECRET . "\nsecond-line"]],
        ] as $override) {
            $response = $this->postJson($this->endpoint('save', ManageController::class), $this->node([
                'id' => $node->id, 'cert_config' => $override + $independent,
            ]))->assertUnprocessable()->assertJsonValidationErrors('cert_config');
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
            $this->assertSame($this->globalCertificate(), $node->fresh()->cert_config);
        }
        $this->postJson($this->endpoint('save', ManageController::class), $this->node([
            'id' => $node->id, 'cert_config' => $independent,
        ]))->assertOk();
        unset($independent['use_global_dns']);
        $this->assertSame($independent, $node->fresh()->cert_config);

        // An old independent record may remain incomplete; this upgrade does not reinterpret it.
        $legacy = Server::create($this->node(['cert_config' => ['cert_mode' => 'dns', 'domain' => 'old.example.com']]));
        $this->postJson($this->endpoint('save', ManageController::class), $this->node([
            'id' => $legacy->id, 'cert_config' => ['cert_mode' => 'dns', 'domain' => 'old.example.com', 'use_global_dns' => false],
        ]))->assertOk();
        $this->assertSame(['cert_mode' => 'dns', 'domain' => 'old.example.com'], $legacy->fresh()->cert_config);
    }

    public function test_switching_away_from_dns_clears_inheritance_without_changing_other_modes(): void
    {
        app(GlobalDnsService::class)->save($this->settings());
        $node = Server::create($this->node(['cert_config' => $this->globalCertificate()]));
        $this->login();
        $http = ['cert_mode' => 'http', 'domain' => 'node.example.com', 'email' => 'http@example.com'];
        $this->postJson($this->endpoint('save', ManageController::class), $this->node(['id' => $node->id, 'cert_config' => $http]))->assertOk();
        $this->assertSame($http, $node->fresh()->cert_config);
        foreach (['none', 'self', 'content'] as $mode) {
            $config = ['cert_mode' => $mode];
            $this->postJson($this->endpoint('save', ManageController::class), $this->node(['id' => $node->id, 'cert_config' => $config]))->assertOk();
            $this->assertSame($config, $node->fresh()->cert_config);
        }

        foreach (['http', 'none'] as $mode) {
            $node = $node->fresh();
            $node->update(['cert_config' => $this->globalCertificate()]);
            $this->postJson($this->endpoint('save', ManageController::class), $this->node([
                'id' => $node->id, 'cert_config' => ['cert_mode' => $mode, 'use_global_dns' => false],
            ]))->assertOk();
            $this->assertSame(['cert_mode' => $mode], $node->fresh()->cert_config);
        }
    }

    public function test_bad_encryption_state_fails_without_exposing_ciphertext_or_request_secret(): void
    {
        DB::table('global_dns_settings')->where('id', 1)->update(['dns_env' => 'synthetic-corrupted-ciphertext', 'revision' => 1]);
        $this->login();
        $fetch = $this->getJson($this->endpoint())->assertStatus(503);
        $save = $this->postJson($this->endpoint('save'), $this->settings(['revision' => 1, 'dns_env' => []]))->assertStatus(503);
        foreach ([$fetch, $save] as $response) {
            $this->assertStringNotContainsString('synthetic-corrupted-ciphertext', $response->getContent());
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        }
    }
}
