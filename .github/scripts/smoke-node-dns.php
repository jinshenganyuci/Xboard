<?php
declare(strict_types=1);

// Invoked by run-node-dns-upgrade.sh in an isolated, synthetic SQLite instance.
// Only custom diagnostics are printed: never HTTP bodies, SQL, tokens or .env.
use App\Models\ExternalSubscription;
use App\Models\ExternalSubscriptionNode;
use App\Models\GlobalDnsSetting;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\ExternalSubscription\Parser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();
$checks = 0;
$stage = getenv('XBOARD_DNS_SMOKE_STAGE') ?: '';
$step = 'guard';
$statePath = '/upgrade-state/fixture.json';

final class DnsUpgradeFailure extends RuntimeException {}

function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new DnsUpgradeFailure($message);
    }
    $checks++;
}

function digest(mixed $value): string
{
    return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
}

function writeState(array $state): void
{
    global $statePath;
    check(file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'Fixture write failed.');
    chmod($statePath, 0600);
}

function localHttp(string $method, string $path, ?array $data = null, ?string $auth = null, array $extraHeaders = []): array
{
    $headers = ['Accept: application/json', 'User-Agent: Xboard-Dns-Upgrade-Smoke/1.0'];
    if ($auth !== null) {
        $headers[] = 'Authorization: ' . $auth;
    }
    $responseHeaders = [];
    $curl = curl_init('http://127.0.0.1:7001' . $path);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($key))] = trim($value);
            }
            return strlen($line);
        },
    ];
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR);
    }
    $options[CURLOPT_HTTPHEADER] = array_merge($headers, $extraHeaders);
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($curl);
    curl_close($curl);
    check(is_string($body), 'Local HTTP transport failed, curl code ' . $error . '.');
    return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
}

function status(array $response, int $expected): void
{
    check($response['status'] === $expected, 'Unexpected HTTP status ' . $response['status'] . '; expected ' . $expected . '.');
}

function data(array $response): array
{
    $json = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    check(is_array($json['data'] ?? null), 'Missing API data object.');
    return $json['data'];
}

function login(User $user, string $password): string
{
    $response = localHttp('POST', '/api/v1/passport/auth/login', ['email' => $user->email, 'password' => $password]);
    status($response, 200);
    $result = data($response);
    check(is_string($result['auth_data'] ?? null) && str_starts_with($result['auth_data'], 'Bearer '), 'Login failed to return authorization.');
    return $result['auth_data'];
}

function nativePayload(int $groupId, string $name, array $cert): array
{
    return ['type' => 'trojan', 'name' => $name, 'host' => 'legacy-node.example.com',
        'port' => '443', 'server_port' => 443, 'group_ids' => [(string) $groupId],
        'route_ids' => [], 'tags' => [], 'show' => true, 'enabled' => true, 'rate' => 1,
        'protocol_settings' => ['network' => 'tcp', 'tls' => 1,
            'tls_settings' => ['server_name' => 'legacy-node.example.com', 'allow_insecure' => false]],
        'cert_config' => $cert];
}

function nativeConfig(array $state, int $id, ?string $etag = null): array
{
    return localHttp('GET', '/api/v1/server/UniProxy/config?' . http_build_query([
        'node_id' => $id, 'node_type' => 'trojan', 'token' => $state['server_token'],
    ]), null, null, $etag === null ? [] : ['If-None-Match: ' . $etag]);
}

function baselineHashes(array $state): array
{
    $user = User::findOrFail($state['user_id']);
    return [
        'app_key' => digest(config('app.key')),
        'user' => digest(array_intersect_key($user->getRawOriginal(), array_flip([
            'id', 'email', 'password', 'token', 'uuid', 'plan_id', 'group_id', 'expired_at',
            'transfer_enable', 'u', 'd', 'banned', 'balance', 'commission_balance',
        ]))),
        'plan' => digest(Plan::findOrFail($state['plan_id'])->getRawOriginal()),
        'group' => digest(ServerGroup::findOrFail($state['group_id'])->getRawOriginal()),
        'legacy_cert' => digest(Server::findOrFail($state['legacy_id'])->getRawOriginal('cert_config')),
        'source' => digest(ExternalSubscription::findOrFail($state['source_id'])->getRawOriginal()),
        'selected' => digest(ExternalSubscriptionNode::findOrFail($state['selected_id'])->getRawOriginal()),
        'unselected' => digest(ExternalSubscriptionNode::findOrFail($state['unselected_id'])->getRawOriginal()),
    ];
}

function noSecret(array $response, array $secrets): void
{
    foreach ($secrets as $secret) {
        check(!str_contains($response['body'], $secret), 'An HTTP response exposed a synthetic DNS credential.');
    }
}

function baselineCheck(array $state): void
{
    check(baselineHashes($state) === $state['baseline'], 'Pre-existing data, ciphertext, selection or APP_KEY changed.');
    $source = ExternalSubscription::findOrFail($state['source_id']);
    check(str_contains($source->url, 'synthetic-upgrade-only'), 'Existing subscription URL no longer decrypts.');
    $selected = ExternalSubscriptionNode::findOrFail($state['selected_id']);
    check(is_array($selected->config) && $selected->selected && $selected->present, 'Existing node configuration or selection was lost.');
    $user = User::findOrFail($state['user_id']);
    $auth = login($user, $state['password']);
    status(localHttp('GET', '/api/v1/user/info', null, $state['old_user_auth'] ?? $auth), 200);
    $sub = localHttp('GET', '/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => 'general']));
    status($sub, 200);
    $decoded = base64_decode(trim($sub['body']), true);
    check(is_string($decoded) && str_contains($decoded, 'DnsUpgradeSelected') && !str_contains($decoded, 'DnsUpgradeUnselected'), 'Existing subscription no longer preserves node selection.');
    check(str_contains($decoded, 'DnsUpgradeLegacy'), 'Existing native node disappeared from subscription.');
    $native = nativeConfig($state, $state['legacy_id']);
    status($native, 200);
    check(digest($native['body']) === $state['legacy_config_digest'], 'An independent legacy node configuration changed.');
}

try {
    check(PHP_SAPI === 'cli' && getenv('DNS_UPGRADE_ALLOW_WRITE') === '1', 'Use the isolated upgrade runner, never a production instance.');
    check(in_array($stage, ['seed', 'baseline', 'exercise', 'persist'], true), 'Invalid upgrade test stage.');
    check(is_dir('/upgrade-state') && is_file('/.dockerenv'), 'Disposable container mounts are missing.');
    chdir('/www');
    require '/www/vendor/autoload.php';
    $app = require '/www/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    check(config('database.default') === 'sqlite', 'The upgrade fixture requires isolated SQLite.');
    if ($stage === 'seed') {
        $step = 'empty-instance guard';
        check(!file_exists($statePath) && !Plan::exists() && !Server::exists() && !ExternalSubscription::exists()
            && !User::where('is_admin', false)->exists(), 'Refusing to seed an existing business database.');
        check(!Schema::hasTable('global_dns_settings'), 'The baseline image already has the new migration.');
        $step = 'seed old-version fixtures';
        $suffix = bin2hex(random_bytes(8));
        $password = 'DnsUpgrade-' . bin2hex(random_bytes(16));
        $group = new ServerGroup();
        $group->name = 'DnsUpgradeSyntheticGroup';
        $group->save();
        $plan = Plan::create(['name' => 'DnsUpgradeSyntheticPlan', 'group_id' => $group->id,
            'transfer_enable' => 10, 'show' => false, 'sell' => false, 'renew' => false, 'prices' => [], 'tags' => []]);
        $users = [];
        foreach (['admin' => true, 'user' => false] as $role => $isAdmin) {
            $users[$role] = User::create(['email' => 'dns-upgrade-' . $role . '-' . $suffix . '@example.com',
                'password' => password_hash($password, PASSWORD_DEFAULT), 'token' => Str::random(32),
                'uuid' => (string) Str::uuid(), 'is_admin' => $isAdmin, 'plan_id' => $plan->id,
                'group_id' => $group->id, 'expired_at' => time() + 86400, 'transfer_enable' => 1073741824,
                'u' => 123, 'd' => 456, 'banned' => false]);
        }
        $legacy = Server::create(nativePayload($group->id, 'DnsUpgradeLegacy', [
            'cert_mode' => 'dns', 'domain' => 'legacy-node.example.com', 'email' => 'legacy@example.com',
            'dns_provider' => 'cloudflare', 'dns_env' => ['CF_API_TOKEN' => 'synthetic-legacy-' . $suffix],
        ]));
        $source = ExternalSubscription::create(['name' => 'DnsUpgradeSyntheticSource',
            'url' => 'https://subscription.example.com/subscribe?token=synthetic-upgrade-only-' . $suffix, 'enabled' => true]);
        $parsed = app(Parser::class)->parse(implode("\n", [
            'trojan://synthetic-upgrade-password@selected.example.com:443?security=tls&sni=selected.example.com#DnsUpgradeSelected',
            'trojan://synthetic-upgrade-password@unselected.example.com:443?security=tls&sni=unselected.example.com#DnsUpgradeUnselected',
        ]));
        check(count($parsed) === 2 && $parsed[0]['supported'] && $parsed[1]['supported'], 'Synthetic upstream parsing failed.');
        $nodes = [];
        foreach ($parsed as $i => $node) {
            $nodes[] = $source->nodes()->create($node + ['selected' => $i === 0, 'present' => true, 'sort_order' => $i]);
        }
        $serverToken = Str::random(32);
        admin_setting(['server_token' => $serverToken]);
        $state = ['marker' => 'xboard-dns-upgrade-v1', 'password' => $password,
            'user_id' => $users['user']->id, 'admin_id' => $users['admin']->id,
            'plan_id' => $plan->id, 'group_id' => $group->id, 'legacy_id' => $legacy->id,
            'source_id' => $source->id, 'selected_id' => $nodes[0]->id, 'unselected_id' => $nodes[1]->id,
            'server_token' => $serverToken];
        $state['old_user_auth'] = login($users['user'], $password);
        $state['baseline'] = baselineHashes($state);
        $native = nativeConfig($state, $legacy->id);
        status($native, 200);
        $state['legacy_config_digest'] = digest($native['body']);
        writeState($state);
    } else {
        $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        check(($state['marker'] ?? null) === 'xboard-dns-upgrade-v1', 'The synthetic fixture marker is missing.');
        check(str_starts_with(User::findOrFail($state['user_id'])->email, 'dns-upgrade-user-'), 'Fixture ownership does not match.');
        $step = 'legacy compatibility';
        baselineCheck($state);
        if ($stage !== 'baseline') {
            $step = 'automatic migration';
            check(Schema::hasTable('global_dns_settings'), 'Image entrypoint did not apply the new migration.');
            check(GlobalDnsSetting::count() === 1, 'Global DNS singleton was duplicated.');
            $adminAuth = login(User::findOrFail($state['admin_id']), $state['password']);
            $userAuth = login(User::findOrFail($state['user_id']), $state['password']);
            $secure = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
            $adminApi = '/api/v2/' . $secure;
            $dnsApi = $adminApi . '/dns-settings/';
            $nodeApi = $adminApi . '/server/manage/';
            if ($stage === 'exercise') {
                $step = 'authorization and unconfigured state';
                status(localHttp('GET', $dnsApi . 'fetch'), 403);
                status(localHttp('GET', $dnsApi . 'fetch', null, $userAuth), 403);
                status(localHttp('POST', $dnsApi . 'save', ['revision' => 0], $userAuth), 403);
                $metaResponse = localHttp('GET', $dnsApi . 'fetch', null, $adminAuth);
                status($metaResponse, 200);
                $meta = data($metaResponse);
                check($meta['configured'] === false && $meta['has_dns_env'] === false && $meta['revision'] === 0, 'New setting is not unconfigured by default.');
                check(str_contains($metaResponse['headers']['cache-control'] ?? '', 'no-store'), 'DNS metadata is cacheable.');
                $payload = nativePayload($state['group_id'], 'DnsUpgradeInherited', ['cert_mode' => 'dns',
                    'domain' => 'inherited.example.com', 'use_global_dns' => true]);
                status(localHttp('POST', $nodeApi . 'save', $payload, $adminAuth), 422);
                check(!Server::where('name', 'DnsUpgradeInherited')->exists(), 'Unconfigured inheritance created a node.');
                $baseSetting = ['revision' => 0, 'dns_provider' => 'cloudflare', 'email' => 'dns-smoke@example.com'];
                status(localHttp('POST', $dnsApi . 'save', $baseSetting, $adminAuth), 422);
                $step = 'encrypted global configuration';
                $secrets = ['dns-smoke-token-A-' . bin2hex(random_bytes(12)), 'dns-smoke-token-B-' . bin2hex(random_bytes(12))];
                $saved = localHttp('POST', $dnsApi . 'save', $baseSetting + ['dns_env' => ['CF_API_TOKEN' => $secrets[0]]], $adminAuth);
                status($saved, 200);
                noSecret($saved, $secrets);
                $meta = data($saved);
                check($meta['configured'] && $meta['has_dns_env'] && $meta['revision'] === 1 && !array_key_exists('dns_env', $meta), 'Global save metadata is invalid.');
                $setting = GlobalDnsSetting::findOrFail(1);
                check($setting->dns_env === ['CF_API_TOKEN' => $secrets[0]], 'Saved global credentials do not decrypt.');
                check(!str_contains($setting->getRawOriginal('dns_env'), $secrets[0]), 'Global DNS credential is stored as plaintext.');
                status(localHttp('POST', $dnsApi . 'save', $baseSetting, $adminAuth), 409);
                $step = 'node inheritance and native delivery';
                status(localHttp('POST', $nodeApi . 'save', $payload, $adminAuth), 200);
                $inherited = Server::where('name', 'DnsUpgradeInherited')->firstOrFail();
                check($inherited->cert_config === $payload['cert_config'], 'Node stores copied global fields or loses the inheritance marker.');
                $config = nativeConfig($state, $inherited->id);
                status($config, 200);
                $effective = json_decode($config['body'], true, 512, JSON_THROW_ON_ERROR)['cert_config'] ?? [];
                check(($effective['dns_env']['CF_API_TOKEN'] ?? '') === $secrets[0] && ($effective['domain'] ?? '') === 'inherited.example.com'
                    && ($effective['email'] ?? '') === 'dns-smoke@example.com' && ($effective['dns_provider'] ?? '') === 'cloudflare'
                    && !array_key_exists('use_global_dns', $effective), 'Authenticated node configuration did not resolve global DNS settings.');
                $etag = $config['headers']['etag'] ?? null;
                check(is_string($etag), 'Native configuration ETag is missing.');
                status(nativeConfig($state, $inherited->id, $etag), 304);
                $step = 'retain credentials on blank edit';
                $cipher = $setting->getRawOriginal('dns_env');
                $preserve = localHttp('POST', $dnsApi . 'save', ['revision' => 1, 'dns_provider' => 'cloudflare',
                    'email' => 'dns-updated@example.com', 'dns_env' => []], $adminAuth);
                status($preserve, 200);
                noSecret($preserve, $secrets);
                check(GlobalDnsSetting::findOrFail(1)->getRawOriginal('dns_env') === $cipher, 'Blank credentials replaced the stored ciphertext.');
                $beforeRotation = nativeConfig($state, $inherited->id);
                status($beforeRotation, 200);
                $etag = $beforeRotation['headers']['etag'];
                $step = 'rotation invalidates ETag';
                $rotated = localHttp('POST', $dnsApi . 'save', ['revision' => 2, 'dns_provider' => 'cloudflare',
                    'email' => 'dns-updated@example.com', 'dns_env' => ['CF_API_TOKEN' => $secrets[1]]], $adminAuth);
                status($rotated, 200);
                noSecret($rotated, $secrets);
                $newConfig = nativeConfig($state, $inherited->id, $etag);
                status($newConfig, 200);
                check(($newConfig['headers']['etag'] ?? '') !== $etag, 'Rotating global credentials did not change the native ETag.');
                $effective = json_decode($newConfig['body'], true, 512, JSON_THROW_ON_ERROR)['cert_config'] ?? [];
                check(($effective['dns_env']['CF_API_TOKEN'] ?? '') === $secrets[1], 'Node still receives previous credentials after rotation.');
                $step = 'confidential API responses';
                foreach ([$dnsApi . 'fetch', $nodeApi . 'getNodes', $adminApi . '/config/fetch'] as $path) {
                    $response = localHttp('GET', $path, null, $adminAuth);
                    status($response, 200);
                    noSecret($response, $secrets);
                }
                foreach (['/api/v1/user/info', '/api/v1/user/server/fetch'] as $path) {
                    $response = localHttp('GET', $path, null, $userAuth);
                    status($response, 200);
                    noSecret($response, $secrets);
                }
                $sub = localHttp('GET', '/api/v1/client/subscribe?' . http_build_query(['token' => User::findOrFail($state['user_id'])->token, 'flag' => 'general']));
                status($sub, 200);
                noSecret(['body' => (string) base64_decode(trim($sub['body']), true)], $secrets);
                $step = 'invalid requests remain safe';
                $invalid = localHttp('POST', $dnsApi . 'save', ['revision' => 3, 'dns_provider' => 'cloudflare',
                    'email' => 'dns-updated@example.com', 'dns_env' => ['CF_API_TOKEN' => $secrets[0] . "\n"]], $adminAuth);
                status($invalid, 422);
                noSecret($invalid, $secrets);
                $badNode = nativePayload($state['group_id'], 'DnsUpgradeInvalid', ['cert_mode' => 'dns', 'domain' => '127.0.0.1', 'use_global_dns' => true]);
                status(localHttp('POST', $nodeApi . 'save', $badNode, $adminAuth), 422);
                check(GlobalDnsSetting::findOrFail(1)->revision === 3, 'Rejected request changed configuration revision.');
                $step = 'audit and application log confidentiality';
                $audit = App\Models\AdminAuditLog::where('admin_id', $state['admin_id'])->get()->toJson();
                foreach ($secrets as $secret) {
                    check(!str_contains($audit, $secret), 'An administrator audit entry exposed a synthetic DNS credential.');
                }
                foreach (glob('/www/storage/logs/*.log') ?: [] as $logPath) {
                    $log = file_get_contents($logPath);
                    foreach ($secrets as $secret) {
                        check(!str_contains($log, $secret), 'An application log exposed a synthetic DNS credential.');
                    }
                }
                $step = 'legacy preservation after edits';
                baselineCheck($state);
                $state['new_node_id'] = $inherited->id;
                $state['global_cipher_digest'] = digest(GlobalDnsSetting::findOrFail(1)->getRawOriginal('dns_env'));
                $state['global_env_digest'] = digest(['CF_API_TOKEN' => $secrets[1]]);
                $state['new_config_digest'] = digest($newConfig['body']);
                $state['migration_count'] = DB::table('migrations')->count();
                writeState($state);
            } else {
                $step = 'second-start persistence';
                $setting = GlobalDnsSetting::findOrFail(1);
                check($setting->revision === 3 && digest($setting->getRawOriginal('dns_env')) === $state['global_cipher_digest']
                    && digest($setting->dns_env) === $state['global_env_digest'], 'Global DNS data changed across container replacement.');
                check(DB::table('migrations')->count() === $state['migration_count'], 'Second startup repeated a migration.');
                check(Server::findOrFail($state['new_node_id'])->cert_config['use_global_dns'] === true, 'Inheritance was lost across container replacement.');
                $response = nativeConfig($state, $state['new_node_id']);
                status($response, 200);
                check(digest($response['body']) === $state['new_config_digest'], 'Effective DNS configuration changed across container replacement.');
                $metadata = localHttp('GET', $dnsApi . 'fetch', null, $adminAuth);
                status($metadata, 200);
                check(data($metadata)['configured'] === true, 'Global settings page loses configured state after restart.');
            }
        }
    }
    ob_end_clean();
    fwrite(STDOUT, 'DNS upgrade ' . $stage . ': PASS (' . $checks . " checks)\n");
    exit(0);
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $message = $error instanceof DnsUpgradeFailure ? $error->getMessage() : 'Unexpected ' . get_class($error) . ' (details suppressed).';
    fwrite(STDERR, 'DNS upgrade ' . $stage . ' failed at ' . $step . ': ' . $message . "\n");
    exit(1);
}
