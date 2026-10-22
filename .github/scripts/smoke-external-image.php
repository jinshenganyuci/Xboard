<?php
declare(strict_types=1);

// Run only inside a newly initialized, disposable release container:
// RELEASE_SMOKE_ALLOW_WRITE=1 php /tmp/release-smoke.php
// HTTP must already listen on 127.0.0.1:7001. No external subscription is fetched.
// NEVER run against the demo or a production database. The empty-instance guard
// rejects any existing plan, native server, external source, or non-admin user.

use App\Models\ExternalSubscription;
use App\Models\ExternalSubscriptionNode;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\ExternalSubscription\Parser;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

final class SmokeFailure extends RuntimeException {}

$stage = 'bootstrap';
$checks = 0;
$users = [];
$source = $plan = $group = null;
$failure = null;

function verify(bool $condition, string $reason): void
{
    global $checks;
    if (!$condition) {
        throw new SmokeFailure($reason);
    }
    $checks++;
}

/** Never include a response body, URL, credential or token in diagnostics. */
function requestLocal(string $method, string $path, ?array $data = null, ?string $authorization = null): array
{
    $headers = ['Accept: application/json', 'User-Agent: Xboard-Release-Smoke/1.0'];
    if ($authorization !== null) {
        $headers[] = 'Authorization: ' . $authorization;
    }
    $responseHeaders = [];
    $curl = curl_init('http://127.0.0.1:7001' . $path);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '',
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
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($curl);
    curl_close($curl);
    if ($body === false) {
        throw new SmokeFailure('Local HTTP transport failed (curl error ' . $error . ').');
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
}

function expectStatus(array $response, int $expected): void
{
    verify($response['status'] === $expected, 'Unexpected HTTP status ' . $response['status'] . '; expected ' . $expected . '.');
}

function responseData(array $response): mixed
{
    $json = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    verify(array_key_exists('data', $json), 'API response is missing its data field.');
    return $json['data'];
}

function login(User $user, string $password): string
{
    $response = requestLocal('POST', '/api/v1/passport/auth/login', ['email' => $user->email, 'password' => $password]);
    expectStatus($response, 200);
    $data = responseData($response);
    verify(is_string($data['auth_data'] ?? null) && str_starts_with($data['auth_data'], 'Bearer '), 'Login did not return bearer authorization.');
    return $data['auth_data'];
}

function subscriptionNodes(array $response, string $format): array
{
    expectStatus($response, 200);
    verify(!str_contains($response['headers']['subscription-userinfo'] ?? '', 'total='), 'External-only response reports a local traffic quota.');
    if ($format === 'meta') {
        $document = Yaml::parse($response['body']);
        return array_map(fn ($node) => ['name' => $node['name'], 'credential' => $node['uuid'] ?? $node['password'] ?? ''], $document['proxies'] ?? []);
    }
    if ($format === 'sing-box') {
        $document = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $nodes = array_filter($document['outbounds'] ?? [], fn ($node) => in_array($node['type'] ?? '', ['vless', 'trojan', 'hysteria2'], true));
        foreach ($nodes as $node) {
            if ($node['type'] === 'hysteria2') {
                verify(($node['server_ports'] ?? []) === ['443:443', '20000:21000'], 'Sing-box lost Hysteria2 hopping ports.');
                verify(!isset($node['server_port']), 'Sing-box contains conflicting fixed and hopping ports.');
            }
        }
        verify(($document['dns']['servers'][0]['type'] ?? null) === 'https', 'Unversioned Sing-box output retained legacy DNS format.');
        return array_values(array_map(fn ($node) => ['name' => $node['tag'], 'credential' => $node['uuid'] ?? $node['password'] ?? ''], $nodes));
    }
    $decoded = base64_decode(trim($response['body']), true);
    verify($decoded !== false, 'URI subscription is not valid Base64.');
    verify(!str_contains($decoded, 'TOT:'), 'External-only Shadowrocket response reports local quota.');
    $nodes = [];
    foreach (preg_split('/\R/', $decoded) as $line) {
        if (!preg_match('/^(vless|trojan|hysteria2):\/\//', $line, $match)) {
            continue;
        }
        if ($format === 'shadowrocket' && $match[1] === 'vless') {
            [$encoded, $query] = explode('?', substr($line, 8), 2);
            $userinfo = base64_decode($encoded, true);
            verify(is_string($userinfo) && str_starts_with($userinfo, 'auto:'), 'Shadowrocket VLESS authentication is invalid.');
            parse_str($query, $parameters);
            $nodes[] = ['name' => $parameters['remark'] ?? '', 'credential' => substr(explode('@', $userinfo, 2)[0], 5)];
        } else {
            $parts = parse_url($line);
            verify(is_array($parts) && isset($parts['user'], $parts['fragment']), 'Generated URI is incomplete.');
            $nodes[] = ['name' => rawurldecode($parts['fragment']), 'credential' => rawurldecode($parts['user'])];
            if ($match[1] === 'hysteria2') {
                parse_str($parts['query'] ?? '', $parameters);
                verify(($parameters['mport'] ?? '') === '443,20000-21000', 'URI output lost Hysteria2 hopping ports.');
            }
        }
    }
    return $nodes;
}

try {
    verify(PHP_SAPI === 'cli' && getenv('RELEASE_SMOKE_ALLOW_WRITE') === '1', 'Set RELEASE_SMOKE_ALLOW_WRITE=1 only in a new disposable container.');
    chdir('/www');
    require '/www/vendor/autoload.php';
    $app = require '/www/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $stage = 'empty-instance guard';
    verify(!ExternalSubscription::exists() && !Server::exists() && !Plan::exists()
        && !User::where('is_admin', false)->exists(), 'Refusing to modify a non-empty instance; use a fresh release-test database.');

    $stage = 'synthetic fixtures';
    $suffix = bin2hex(random_bytes(6));
    $password = 'Release-Smoke-' . bin2hex(random_bytes(12));
    $group = new ServerGroup();
    $group->name = 'Release smoke group';
    $group->save();
    $plan = Plan::create(['name' => 'Release smoke zero-traffic plan', 'group_id' => $group->id,
        'transfer_enable' => 0, 'show' => false, 'sell' => false, 'renew' => false, 'prices' => [], 'tags' => []]);
    foreach (['admin' => ['is_admin' => true], 'active' => [], 'exhausted' => ['transfer_enable' => 10, 'u' => 20],
        'expired' => ['expired_at' => time() - 3600], 'no-plan' => ['plan_id' => null], 'banned' => ['banned' => true]] as $role => $overrides) {
        $users[$role] = User::create(array_merge([
            'email' => 'release-smoke-' . $role . '-' . $suffix . '@example.com', 'password' => password_hash($password, PASSWORD_DEFAULT),
            'token' => Str::random(32), 'uuid' => (string) Str::uuid(), 'is_admin' => false,
            'plan_id' => $plan->id, 'group_id' => $group->id, 'expired_at' => time() + 3600,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'banned' => false,
        ], $overrides));
    }
    $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
    $api = '/api/v2/' . $securePath . '/external-subscription/';
    $stage = 'web pages and assets';
    foreach (['/', '/' . $securePath, '/' . $securePath . '/external-subscriptions',
        '/assets/external-subscriptions/app.js', '/assets/external-subscriptions/app.css'] as $path) {
        $response = requestLocal('GET', $path);
        expectStatus($response, 200);
        verify(strlen($response['body']) > 100, 'A page or required asset is unexpectedly empty.');
    }
    $stage = 'login and administrator authorization';
    expectStatus(requestLocal('GET', $api . 'fetch'), 403);
    $adminAuth = login($users['admin'], $password);
    $userAuth = login($users['active'], $password);
    expectStatus(requestLocal('GET', $api . 'fetch', null, $userAuth), 403);
    expectStatus(requestLocal('POST', $api . 'select', ['id' => 1, 'node_ids' => []], $userAuth), 403);
    $created = requestLocal('POST', $api . 'save', ['name' => 'Release smoke source',
        'url' => 'https://subscription.example.com/subscribe?token=synthetic-only', 'enabled' => true], $adminAuth);
    expectStatus($created, 200);
    $createdData = responseData($created);
    verify(($createdData['enabled'] ?? null) === false, 'New source is enabled before administrator review.');
    verify(!isset($createdData['url']) && !str_contains($created['body'], 'synthetic-only'), 'Administrator response exposes upstream URL credentials.');
    $source = ExternalSubscription::findOrFail($createdData['id']);

    $fixtureUris = [
        'vless://11111111-2222-4333-8444-555555555555@vless.example.com:443?security=tls&sni=tls.example.com&type=ws&host=ws.example.com&path=%2Fsmoke#ReleaseSmokeVLESS',
        'hysteria2://synthetic%40hy2%2Fpassword@hy2.example.com:443?sni=tls.example.com&obfs=salamander&obfs-password=synthetic-obfs&mport=443%2C20000-21000#ReleaseSmokeHy2',
        'trojan://synthetic%40trojan%2Fpassword@trojan.example.com:443?security=tls&sni=tls.example.com&type=grpc&serviceName=smoke#ReleaseSmokeTrojan',
    ];
    $parsed = app(Parser::class)->parse(implode("\n", $fixtureUris));
    verify(count($parsed) === 3 && collect($parsed)->every(fn ($node) => $node['supported']), 'Synthetic fixture parsing failed.');
    $nodes = [];
    foreach ($parsed as $index => $node) {
        $nodes[] = $source->nodes()->create($node + ['selected' => false, 'present' => true, 'sort_order' => $index]);
    }

    foreach ([[0, 1], [1, 2]] as $selection) {
        $stage = 'administrator node selection';
        $ids = array_map(fn ($index) => $nodes[$index]->id, $selection);
        $selected = requestLocal('POST', $api . 'select', ['id' => $source->id, 'node_ids' => $ids], $adminAuth);
        expectStatus($selected, 200);
        verify(responseData($selected)['selected_count'] === 2, 'Administrator selection did not select exactly two nodes.');
        $enabled = requestLocal('POST', $api . 'save', ['id' => $source->id, 'name' => $source->name, 'enabled' => true], $adminAuth);
        expectStatus($enabled, 200);
        $listing = requestLocal('GET', $api . 'nodes?id=' . $source->id, null, $adminAuth);
        expectStatus($listing, 200);
        $listed = responseData($listing);
        verify(count($listed) === 3 && count(array_filter($listed, fn ($node) => $node['selected'])) === 2, 'Node listing selection is inconsistent.');
        foreach ($listed as $node) {
            verify(!array_key_exists('config', $node) && !array_key_exists('password', $node), 'Node-management API exposes credentials.');
        }
        foreach (['general', 'shadowrocket', 'meta', 'sing-box'] as $format) {
            $stage = 'subscription format ' . $format;
            $response = requestLocal('GET', '/api/v1/client/subscribe?' . http_build_query(['token' => $users['active']->token, 'flag' => $format]));
            $delivered = subscriptionNodes($response, $format);
            foreach ($nodes as $index => $node) {
                $matching = array_values(array_filter($delivered, fn ($item) => str_contains($item['name'], $node->name . '〔外部' . $node->id . '〕')));
                if (in_array($index, $selection, true)) {
                    verify(count($matching) === 1, 'A selected node is missing or duplicated.');
                    verify($matching[0]['credential'] === $node->config['password'], 'Exporter changed an upstream credential.');
                } else {
                    verify($matching === [], 'An unselected node was delivered.');
                }
            }
            verify(!str_contains($response['body'], $users['active']->uuid), 'A subscription substituted the local user UUID.');
        }
    }
    $stage = 'membership eligibility and legacy links';
    expectStatus(requestLocal('GET', '/api/v1/client/subscribe?token=unknown-release-smoke-token'), 403);
    foreach (['expired', 'no-plan', 'banned'] as $role) {
        expectStatus(requestLocal('GET', '/api/v1/client/subscribe?' . http_build_query(['token' => $users[$role]->token, 'flag' => 'general'])), 403);
    }
    $shortPath = '/' . admin_setting('subscribe_path', 's') . '/' . $users['exhausted']->token . '?flag=general';
    $exhaustedNodes = subscriptionNodes(requestLocal('GET', $shortPath), 'general');
    verify(count($exhaustedNodes) >= 2, 'An active user with exhausted local quota cannot subscribe.');
    $stage = 'safe user node listing';
    $dashboard = requestLocal('GET', '/api/v1/user/server/fetch', null, $userAuth);
    expectStatus($dashboard, 200);
    $safeNodes = responseData($dashboard);
    verify(count($safeNodes) === 2, 'User dashboard does not show exactly the selected nodes.');
    foreach ($safeNodes as $node) {
        foreach (['password', 'config', 'url', 'host'] as $field) {
            verify(!array_key_exists($field, $node), 'User dashboard exposes private node fields.');
        }
        verify(($node['external'] ?? false) === true && ($node['is_online'] ?? null) === null, 'External dashboard status is misleading.');
    }
} catch (Throwable $exception) {
    $failure = $stage . ': ' . ($exception instanceof SmokeFailure ? $exception->getMessage() : 'Unexpected ' . get_class($exception) . '; details intentionally suppressed.');
} finally {
    try {
        if ($source !== null) {
            $source->nodes()->delete();
            $source->delete();
        }
        foreach ($users as $user) {
            $user->tokens()->delete();
            $user->delete();
        }
        $plan?->delete();
        $group?->delete();
    } catch (Throwable $exception) {
        $failure ??= 'cleanup: Could not remove all synthetic fixtures; discard this release-test container and database.';
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    exit(1);
}
echo 'PASS: ' . $checks . ' checks; pages/assets, login/admin authorization, two-of-three node selection, four subscription formats, zero/exhausted quota, eligibility 403s, safe dashboard; synthetic fixtures removed.' . PHP_EOL;
