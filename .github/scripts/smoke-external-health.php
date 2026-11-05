<?php
declare(strict_types=1);

// Works only with the disposable network, temporary CA and synthetic peers created
// by run-health-smoke.sh. The Compose project owns and removes every fixture.
use App\Models\ExternalSubscription;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\ExternalSubscription\Parser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

final class HealthSmokeFailure extends RuntimeException {}

function check(bool $ok, string $message): void {
    if (!$ok) { throw new HealthSmokeFailure($message); }
}
function until(callable $condition, int $seconds, string $message): void {
    $deadline = microtime(true) + $seconds;
    do {
        if ($condition()) { return; }
        usleep(250000);
    } while (microtime(true) < $deadline);
    throw new HealthSmokeFailure($message);
}
function localApi(string $path, ?string $auth = null, ?array $post = null): array {
    $curl = curl_init('http://127.0.0.1:7001' . $path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($auth !== null) { $headers[] = 'Authorization: ' . $auth; }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($post, JSON_THROW_ON_ERROR)); }
    $body = curl_exec($curl);
    check(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200, 'User API returned an unexpected status');
    curl_close($curl);
    return json_decode($body, true, flags: JSON_THROW_ON_ERROR)['data'];
}

function runChecks(): void
{
    check(PHP_SAPI === 'cli' && getenv('RELEASE_SMOKE_ALLOW_WRITE') === '1' && is_dir('/tmp/health-fixture'), 'Disposable container required');
    chdir('/www');
    require '/www/vendor/autoload.php';
    $app = require '/www/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    check(!ExternalSubscription::exists() && !Plan::exists() && !Server::exists() && !User::where('is_admin', false)->exists(), 'Refusing an existing business database');
    foreach (['www.gstatic.com', 'cp.cloudflare.com'] as $host) {
        check(gethostbyname($host) === '11.254.233.2', 'Test HTTPS host must resolve locally');
    }
    until(fn () => (bool) @fsockopen('11.254.233.2', 19001), 10, 'Synthetic proxy did not start');
    $group = new ServerGroup(); $group->name = 'Health smoke'; $group->save();
    $plan = Plan::create(['name' => 'Health smoke', 'group_id' => $group->id, 'transfer_enable' => 0, 'show' => false, 'sell' => false]);
    $password = Str::random(25);
    $user = User::create(['email' => 'health-member@example.invalid', 'password' => password_hash($password, PASSWORD_DEFAULT),
        'token' => Str::random(32), 'uuid' => (string) Str::uuid(), 'plan_id' => $plan->id, 'group_id' => $group->id,
        'expired_at' => time()+7200, 'u' => 0, 'd' => 0, 'transfer_enable' => 0, 'banned' => false]);
    $auth = localApi('/api/v1/passport/auth/login', post: ['email' => $user->email, 'password' => $password])['auth_data'];
    $source = ExternalSubscription::create(['name' => 'Health smoke', 'enabled' => false, 'url' => 'https://fixture.invalid/sub']);
    $queries = [
        'vless://75fc28e4-e2fa-4584-9685-f8f1651a6336@11.254.233.2:19001?security=tls&sni=health.fixture.invalid#VLESS',
        'trojan://health-fixture-secret@11.254.233.2:19002?security=tls&sni=health.fixture.invalid#Trojan',
        'hysteria2://health-fixture-secret@11.254.233.2:19003?sni=health.fixture.invalid#HY2',
        'anytls://health-fixture-secret@11.254.233.2:19004?security=tls&type=tcp&packetEncoding=none&sni=health.fixture.invalid&udp=1#AnyTLS',
        'vless://06aa6fde-4c0f-42ed-8efb-35c1772a8402@11.254.233.2:19001?security=tls&sni=health.fixture.invalid#Account',
        'trojan://health-fixture-secret@11.254.233.2:19002?security=tls&sni=health.fixture.invalid#Unselected',
    ];
    $nodes = [];
    foreach (app(Parser::class)->parse(implode("\n", $queries)) as $i => $row) {
        check($row['supported'], 'Bad synthetic node configuration');
        $nodes[$row['name']] = $source->nodes()->create($row + ['selected' => $row['name'] !== 'Unselected', 'present' => true, 'sort_order' => $i]);
    }
    $source->update(['enabled' => true]);
    $dashboard = fn () => collect(localApi('/api/v1/user/server/fetch', $auth))->keyBy('name');
    $expect = function (string $name, string $status, ?bool $online) use ($dashboard) {
        $row = $dashboard()->get($name);
        check($row && $row['health_status'] === $status && $row['is_online'] === $online, 'Dashboard ' . $name . ' expected ' . $status . ' but observed ' . ($row['health_status'] ?? 'missing'));
        check(is_int($row['last_check_at']), 'Missing last check time');
        check($online === true ? is_int($row['latency_ms']) && $row['latency_ms'] > 0 : $row['latency_ms'] === null, 'Latency must describe only a fresh successful proxy request');
        check(!array_intersect(['config', 'password', 'url', 'host'], array_keys($row)), 'Private configuration in dashboard');
    };
    // First cycle MUST be automatic. All four real protocols and an open TLS port
    // with invalid credentials share the same cert and reachable HTTPS destination.
    until(function () use ($nodes) {
        foreach (['VLESS'=>'online','Trojan'=>'online','HY2'=>'online','AnyTLS'=>'online','Account'=>'pending'] as $name=>$state) {
            if ($nodes[$name]->fresh()->health_status !== $state) { return false; }
        }
        return true;
    }, 160, 'Automatic real protocol checks did not finish with expected states');
    foreach (['VLESS','Trojan','HY2','AnyTLS'] as $name) { $expect($name, 'online', true); }
    $expect('Account', 'pending', null);
    check(!$dashboard()->has('Unselected') && $nodes['Unselected']->fresh()->health_checked_at === null, 'Unselected node was probed or published');
    print "PASS: unattended VLESS/Trojan/Hysteria2/AnyTLS HTTPS latency and first invalid-credential failure\n";
    $account = $nodes['Account'];
    $next = $account->fresh()->health_due_at;
    check($next > time() && $next <= time()+301, 'Confirmation not due within five minutes');
    $checkDue = function ($node) {
        $revision = $node->fresh()->health_revision;
        // Bypass the previously loaded model's dirty tracking when two simulated
        // hourly checks are advanced within the same real second.
        $node->newQuery()->whereKey($node->id)->update(['health_due_at' => time()-1]);
        until(function () use ($node, $revision) {
            check(Artisan::call('external-subscriptions:health', ['--slot' => 0]) === 0, 'Health command failed');
            return $node->fresh()->health_revision >= $revision + 2;
        }, 40, 'A due probe did not finish');
    };
    $checkDue($account);
    $expect('Account', 'offline', false);
    $body = file_get_contents('http://127.0.0.1:7001/api/v1/client/subscribe?' . http_build_query(['token'=>$user->token, 'flag'=>'general']));
    check(str_contains(base64_decode($body, true), '06aa6fde-4c0f-42ed-8efb-35c1772a8402'), 'Offline status unexpectedly removed a selected subscription node');
    print "PASS: backup failure marked offline without removing the selected node\n";
    touch('/tmp/health-fixture/restore-account');
    until(fn () => file_exists('/tmp/health-fixture/restored') && (bool) @fsockopen('11.254.233.2',19001), 10, 'Fixture account recovery not ready');
    $checkDue($account);
    $expect('Account', 'online', true);
    $revision = $account->fresh()->health_revision;
    Artisan::call('external-subscriptions:health', ['--slot'=>0]);
    $dashboard();
    check($account->fresh()->health_revision === $revision && $account->fresh()->health_due_at > time()+3500, 'Fresh node was re-probed');
    print "PASS: recovered node online again; page visits and early scans consume no extra probes\n";
    touch('/tmp/health-fixture/origin-down');
    $checkDue($account);
    $expect('Account', 'unknown', null);
    unlink('/tmp/health-fixture/origin-down');
    $nodes['Trojan']->update(['health_checked_at' => time()-7201]);
    $expect('Trojan', 'unknown', null);
    print "PASS: failing HTTPS target and stale observations appear as unknown\n";
}

try {
    runChecks();
} catch (Throwable $error) {
    $message = $error instanceof HealthSmokeFailure ? $error->getMessage() : get_class($error);
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
