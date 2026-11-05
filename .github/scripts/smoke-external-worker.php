<?php
declare(strict_types=1);

// Destructive fixtures are permitted ONLY in a new disposable installed database.
// Run with RELEASE_SMOKE_ALLOW_WRITE=1 in an INTERNAL Docker network whose app
// container owns 11.254.233.2. That public-formatted address exercises the real
// Fetcher without disabling SSRF checks or contacting an external service.
// Disable the web service (or Octane task workers) to prove the independent worker.

use App\Models\ExternalSubscription;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\ExternalSubscription\NodeProvider;
use App\Services\ExternalSubscription\SyncService;
use Illuminate\Support\Facades\Cache;

ini_set('display_errors', '0');
error_reporting(E_ALL);

final class WorkerSmokeFailure extends RuntimeException {}

$stage = 'bootstrap';
$source = null;
$temporaryDirectory = null;
$fixtureServer = null;
$failure = null;
$fixtureIp = '11.254.233.2';
$fixturePort = 18080;

function requireWorkerCheck(bool $passed, string $reason): void
{
    if (!$passed) {
        throw new WorkerSmokeFailure($reason);
    }
}

function workerPhase(string $message): void
{
    echo 'PASS: ' . $message . PHP_EOL;
    flush();
}

function replaceWorkerFixture(string $directory, string $body): void
{
    $next = $directory . '/nodes.next';
    requireWorkerCheck(file_put_contents($next, $body) !== false, 'Could not write the synthetic fixture.');
    requireWorkerCheck(rename($next, $directory . '/nodes'), 'Could not atomically replace the synthetic fixture.');
}

/** Poll only: never invoke the sync command or SyncService during either cycle. */
function waitForWorkerRefresh(int $sourceId, int $oldTimestamp, $fixtureServer): ExternalSubscription
{
    $deadline = microtime(true) + 80;
    $fresh = null;
    while (microtime(true) < $deadline) {
        requireWorkerCheck(proc_get_status($fixtureServer)['running'], 'The synthetic HTTP server stopped unexpectedly.');
        $fresh = ExternalSubscription::find($sourceId);
        requireWorkerCheck($fresh !== null, 'The synthetic source disappeared unexpectedly.');
        if ($fresh->last_synced_at !== null && $fresh->last_synced_at->timestamp > $oldTimestamp) {
            requireWorkerCheck($fresh->last_error === null, 'Automatic refresh retained an error status.');
            return $fresh;
        }
        usleep(500000);
    }
    throw new WorkerSmokeFailure('No automatic refresh completed within 80 seconds'
        . ($fresh?->last_error !== null ? ' (the source reports a synchronization error).' : '.'));
}

try {
    requireWorkerCheck(PHP_SAPI === 'cli' && getenv('RELEASE_SMOKE_ALLOW_WRITE') === '1',
        'Set RELEASE_SMOKE_ALLOW_WRITE=1 only for a new disposable database.');
    chdir('/www');
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        require '/www/vendor/autoload.php';
        $app = require '/www/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    } finally {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
    }
    $stage = 'empty-instance guard';
    requireWorkerCheck(!ExternalSubscription::exists() && !Server::exists() && !Plan::exists()
        && !User::where(fn ($query) => $query->where('is_admin', false)->orWhereNull('is_admin'))->exists(),
        'Refusing to use an instance with existing sources, servers, plans or non-admin users.');
    requireWorkerCheck(filter_var(env('ENABLE_EXTERNAL_SUBSCRIPTIONS_SYNC', true), FILTER_VALIDATE_BOOLEAN),
        'Automatic external subscription synchronization is disabled.');
    requireWorkerCheck(function_exists('proc_open'), 'The release runtime cannot start the synthetic fixture server.');

    // Refuse to use the fixture address unless it is actually assigned locally.
    $probe = @stream_socket_server('tcp://' . $fixtureIp . ':0', $errorCode, $errorMessage, STREAM_SERVER_BIND);
    requireWorkerCheck(is_resource($probe), 'The required isolated-network address is not assigned to this container.');
    fclose($probe);
    workerPhase('empty disposable instance and isolated local fixture address confirmed');

    $stage = 'synthetic HTTP fixture';
    $temporaryDirectory = sys_get_temp_dir() . '/xboard-worker-smoke-' . bin2hex(random_bytes(8));
    requireWorkerCheck(mkdir($temporaryDirectory, 0700), 'Could not create the synthetic fixture directory.');
    $nodeA = 'trojan://synthetic-a@a.example.com:443?security=tls&sni=tls.example.com#WorkerSmokeA';
    $nodeB = 'trojan://synthetic-b@b.example.com:443?security=tls&sni=tls.example.com#WorkerSmokeB';
    $nodeC = 'trojan://synthetic-c@c.example.com:443?security=tls&sni=tls.example.com#WorkerSmokeC';
    $initialBody = $nodeA . "\n" . $nodeB . "\n";
    replaceWorkerFixture($temporaryDirectory, $initialBody);
    $fixtureServer = proc_open(
        [PHP_BINARY, '-S', '0.0.0.0:' . $fixturePort, '-t', $temporaryDirectory],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
        $pipes,
        $temporaryDirectory
    );
    requireWorkerCheck(is_resource($fixtureServer), 'Could not start the synthetic fixture server.');
    $ready = false;
    $readyDeadline = microtime(true) + 5;
    while (microtime(true) < $readyDeadline) {
        requireWorkerCheck(proc_get_status($fixtureServer)['running'], 'The synthetic HTTP server could not bind its port.');
        $body = @file_get_contents('http://127.0.0.1:' . $fixturePort . '/nodes', false,
            stream_context_create(['http' => ['timeout' => 1]]));
        if ($body === $initialBody) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    requireWorkerCheck($ready, 'The synthetic fixture server did not become ready.');

    $stage = 'disabled-source initialization';
    $source = ExternalSubscription::create([
        'name' => 'Independent worker smoke', 'url' => 'http://' . $fixtureIp . ':' . $fixturePort . '/nodes', 'enabled' => false,
    ]);
    // The sole explicit synchronization initializes fixtures while automatic sync is disabled.
    $initial = app(SyncService::class)->sync($source);
    requireWorkerCheck($initial['total'] === 2 && $initial['supported'] === 2, 'The real Fetcher could not initialize two synthetic nodes.');
    $source->nodes()->update(['selected' => true]);
    $initialNodes = $source->nodes()->get()->keyBy('name');
    $originalA = $initialNodes['WorkerSmokeA']->id;
    $originalB = $initialNodes['WorkerSmokeB']->id;
    $user = (new User())->forceFill([
        'plan_id' => 1, 'banned' => false, 'expired_at' => time() + 3600,
        'transfer_enable' => 0, 'u' => 0, 'd' => 0,
    ]);
    requireWorkerCheck(app(NodeProvider::class)->forUser($user) === [], 'A disabled source is deliverable.');
    workerPhase('real Fetcher initialized selected A/B on a disabled source');

    $stage = 'first automatic cycle';
    replaceWorkerFixture($temporaryDirectory, $nodeB . "\n" . $nodeC . "\n");
    $oldTimestamp = time() - 360;
    $source->forceFill(['enabled' => true, 'last_synced_at' => date('Y-m-d H:i:s', $oldTimestamp)])->save();
    workerPhase('B/C fixture armed; waiting up to 80 seconds for the independent worker');
    $source = waitForWorkerRefresh($source->id, $oldTimestamp, $fixtureServer);
    $nodes = $source->nodes()->get()->keyBy('name');
    requireWorkerCheck($nodes->keys()->sort()->values()->all() === ['WorkerSmokeB', 'WorkerSmokeC'],
        'Automatic replacement did not leave exactly B/C.');
    requireWorkerCheck(!$source->nodes()->whereKey($originalA)->exists(), 'The previously selected removed node A remains stored.');
    requireWorkerCheck($nodes['WorkerSmokeB']->id === $originalB && $nodes['WorkerSmokeB']->selected,
        'Automatic replacement did not preserve selected node B.');
    requireWorkerCheck(!$nodes['WorkerSmokeC']->selected, 'A newly discovered node was automatically selected.');
    requireWorkerCheck(array_column(app(NodeProvider::class)->forUser($user), 'name') === ['WorkerSmokeB'],
        'Delivery after replacement is not restricted to selected node B.');
    workerPhase('automatic cycle 1 removed selected A, retained selected B and left new C unselected');

    $stage = 'second automatic cycle';
    replaceWorkerFixture($temporaryDirectory, "proxies: []\n");
    $oldTimestamp = time() - 360;
    $source->forceFill(['last_synced_at' => date('Y-m-d H:i:s', $oldTimestamp)])->save();
    // Backdating simulates six elapsed minutes. Remove only this synthetic source's
    // one-minute attempt marker so its real-time TTL cannot skew the simulated age.
    Cache::forget('external-subscriptions:attempt:' . $source->id);
    workerPhase('empty snapshot armed; waiting up to 80 seconds for the next automatic cycle');
    $source = waitForWorkerRefresh($source->id, $oldTimestamp, $fixtureServer);
    requireWorkerCheck($source->nodes()->count() === 0, 'The automatic empty snapshot did not remove all cached nodes.');
    requireWorkerCheck(app(NodeProvider::class)->forUser($user) === [], 'Delivery still contains nodes after an automatic empty snapshot.');
    workerPhase('automatic cycle 2 accepted the empty snapshot and cleared stored and delivered nodes');
} catch (Throwable $exception) {
    $failure = $stage . ': ' . ($exception instanceof WorkerSmokeFailure
        ? $exception->getMessage()
        : 'Unexpected ' . get_class($exception) . '; diagnostic details intentionally suppressed.');
} finally {
    try {
        if ($source !== null) {
            $source->update(['enabled' => false]);
            $source->nodes()->delete();
            $source->delete();
            Cache::forget('external-subscriptions:attempt:' . $source->id);
        }
    } catch (Throwable $exception) {
        $failure ??= 'cleanup: synthetic database fixtures could not be fully removed; discard this disposable instance.';
    }
    if (is_resource($fixtureServer)) {
        if (proc_get_status($fixtureServer)['running']) {
            proc_terminate($fixtureServer);
        }
        $stopDeadline = microtime(true) + 2;
        while (proc_get_status($fixtureServer)['running'] && microtime(true) < $stopDeadline) {
            usleep(100000);
        }
        if (proc_get_status($fixtureServer)['running']) {
            proc_terminate($fixtureServer, 9);
        }
        proc_close($fixtureServer);
    }
    if ($temporaryDirectory !== null && is_dir($temporaryDirectory)) {
        foreach (['nodes', 'nodes.next'] as $file) {
            if (is_file($temporaryDirectory . '/' . $file)) {
                if (!@unlink($temporaryDirectory . '/' . $file)) {
                    $failure ??= 'cleanup: a synthetic fixture file could not be removed.';
                }
            }
        }
        if (!@rmdir($temporaryDirectory)) {
            $failure ??= 'cleanup: the synthetic fixture directory could not be removed.';
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    exit(1);
}
workerPhase('independent worker smoke completed; synthetic source, fixtures and HTTP subprocess removed');
