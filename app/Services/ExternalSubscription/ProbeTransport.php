<?php

namespace App\Services\ExternalSubscription;

use App\Protocols\SingBox;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Only a verified HTTPS 204 through the authenticated proxy can mark a node online. */
class ProbeTransport
{
    private const BINARY = '/usr/local/bin/sing-box';
    private const WATCHDOG = '/usr/bin/timeout';
    private const PRIMARY = 'https://www.gstatic.com/generate_204';
    private const BACKUP = 'https://cp.cloudflare.com/generate_204';

    public function __construct(private Fetcher $addresses) {}

    /** true: verified 204; false: failed proxy path; null: no reliable measurement. */
    public function check(array $config, bool $backup = false): ?bool
    {
        $process = null;
        try {
            if (!is_executable(self::BINARY) || !is_executable(self::WATCHDOG) || !function_exists('curl_init') || !function_exists('proc_open')) {
                return null;
            }
            $outbound = $this->pinnedOutbound($config, $backup);
            if ($outbound === null) {
                return null;
            }

            $port = $this->localPort();
            $username = bin2hex(random_bytes(16));
            $password = bin2hex(random_bytes(24));
            $json = json_encode($this->configuration($outbound, $port, $username, $password), JSON_THROW_ON_ERROR);

            // No template, shell, credential-bearing argv, diagnostic output or on-disk config.
            // The outer watchdog also stops the process if its PHP parent is killed.
            $process = new Process([self::WATCHDOG, '-s', 'KILL', '14', self::BINARY, 'run', '-c', 'stdin'],
                sys_get_temp_dir(), $this->childEnvironment(), $json, 14);
            $process->disableOutput();
            $process->start();
            if (!$this->waitForListener($process, $port)) {
                return null;
            }

            $result = $this->request204($backup ? self::BACKUP : self::PRIMARY, [
                CURLOPT_PROXY => '127.0.0.1:' . $port, CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
                CURLOPT_PROXYUSERNAME => $username, CURLOPT_PROXYPASSWORD => $password, CURLOPT_NOPROXY => '',
            ], 8000);
            $result = $process->isRunning() ? $result : null;
        } catch (Throwable $e) {
            // Resolver/renderer/process errors must not leak a hostname, token or password.
            return null;
        } finally {
            try {
                $process?->stop(0.3);
            } catch (Throwable $e) {
                // The external watchdog still bounds the lifetime of an orphaned child.
            }
        }
        return $this->applyBackupControl($result, $backup);
    }

    /** Build transport Host/SNI from the original name before replacing its dial address. */
    protected function pinnedOutbound(array $config, bool $backup): ?array
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        if (!is_string($host) || $host === '' || strlen($host) > 253 || !is_int($port) || $port < 1 || $port > 65535) {
            return null;
        }
        $addresses = $this->addresses->publicAddresses($host, fn ($name) => $this->resolveDns($name));
        // A secondary attempt may also try the other address family for a dual-stack endpoint.
        $v4 = array_values(array_filter($addresses, fn ($ip) => !str_contains($ip, ':')));
        $v6 = array_values(array_filter($addresses, fn ($ip) => str_contains($ip, ':')));
        $candidates = $backup ? array_merge($v6, array_reverse($v4)) : array_merge($v4, $v6);
        $config['name'] = 'probe-node';
        $outbound = (new SingBox([], []))->externalOutbound($config);
        if ($outbound === null) {
            return null;
        }
        $outbound['server'] = $candidates[0];
        if (!empty($outbound['tls']['enabled']) && empty($outbound['tls']['server_name'])) {
            $outbound['tls']['server_name'] = $host;
        }
        // WebSocket and HTTP transports otherwise infer Host from the now-pinned IP.
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $transport = $outbound['transport']['type'] ?? null;
            if ($transport === 'ws' && empty($outbound['transport']['headers']['Host'])) {
                $outbound['transport']['headers']['Host'] = $host;
            } elseif (in_array($transport, ['http', 'httpupgrade'], true) && empty($outbound['transport']['host'])) {
                $outbound['transport']['host'] = $transport === 'http' ? [$host] : $host;
            }
        }
        return $outbound;
    }

    /** DNS in a bounded child: synchronous system resolver calls have no PHP timeout. */
    protected function resolveDns(string $host): array
    {
        $code = <<<'PHP'
        $records = @dns_get_record(stream_get_contents(STDIN), DNS_A | DNS_AAAA);
        echo json_encode(array_values(array_filter(array_map(static fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
        PHP;
        $lookup = new Process([PHP_BINARY, '-n', '-d', 'display_errors=0', '-r', $code],
            sys_get_temp_dir(), $this->childEnvironment(), $host, 3);
        try {
            if ($lookup->run() !== 0) {
                throw new RuntimeException();
            }
            $addresses = json_decode($lookup->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($addresses) || !$addresses || count($addresses) > 128) {
                throw new RuntimeException();
            }
            return $addresses;
        } finally {
            $lookup->stop(0.2);
        }
    }

    private function childEnvironment(): array
    {
        // Symfony Process inherits environment variables unless explicitly removed.
        $env = array_fill_keys(array_keys(array_merge($_SERVER, $_ENV, getenv())), false);
        $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
        $env['GOMAXPROCS'] = '1';
        return $env;
    }

    protected function configuration(array $outbound, int $port, string $username, string $password): array
    {
        return [
            'log' => ['disabled' => true],
            'inbounds' => [['type' => 'socks', 'listen' => '127.0.0.1', 'listen_port' => $port,
                'users' => [['username' => $username, 'password' => $password]]]],
            'outbounds' => [$outbound],
            'route' => ['final' => 'probe-node'],
        ];
    }

    private function localPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) {
            throw new RuntimeException();
        }
        try {
            return (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        } finally {
            fclose($socket);
        }
    }

    private function waitForListener(Process $process, int $port): bool
    {
        $deadline = hrtime(true) + 2_500_000_000;
        do {
            if (!$process->isRunning()) {
                return false;
            }
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.05);
            if ($socket) {
                fclose($socket);
                return true;
            }
            usleep(20000);
        } while (hrtime(true) < $deadline);
        return false;
    }

    /** Direct control is a veto only: it can change offline to unknown, never to online. */
    protected function applyBackupControl(?bool $proxyResult, bool $backup): ?bool
    {
        return $backup && $proxyResult === false && !$this->backupBaselineAvailable() ? null : $proxyResult;
    }

    protected function backupBaselineAvailable(): bool
    {
        $lock = null;
        $locked = false;
        try {
            // Workers on this host share one short control; another host's uplink may differ.
            $key = 'external-subscriptions:probe:backup-control:' . sha1((string) gethostname());
            $cached = Cache::get($key);
            if (is_bool($cached)) {
                return $cached;
            }
            $lock = Cache::lock($key . ':lock', 6);
            if (!($locked = $lock->get())) {
                return false;
            }
            $cached = Cache::get($key);
            if (is_bool($cached)) {
                return $cached;
            }
            $available = $this->request204(self::BACKUP, [CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'], 2500) === true;
            Cache::put($key, $available, 30);
            return $available;
        } catch (Throwable $e) {
            return false;
        } finally {
            if ($locked) {
                try {
                    $lock->release();
                } catch (Throwable $e) {
                    // Cache/lock infrastructure cannot certify a failed node.
                }
            }
        }
    }

    protected function request204(string $target, array $networkOptions, int $timeoutMs): ?bool
    {
        $curl = curl_init();
        if (!$curl) {
            return null;
        }
        $bytes = 0;
        try {
            if (!curl_setopt_array($curl, $networkOptions + [
                CURLOPT_URL => $target, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true, CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $body) use (&$bytes): int {
                    $bytes += strlen($body);
                    return $bytes <= 4096 ? strlen($body) : 0;
                },
            ])) {
                return null;
            }
            $ok = curl_exec($curl) !== false;
            return $this->classify($ok, curl_errno($curl), (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $bytes);
        } finally {
            curl_close($curl);
        }
    }

    protected function classify(bool $ok, int $error, int $status, int $bytes): ?bool
    {
        if ($ok && $status === 204 && $bytes === 0) {
            return true;
        }
        // Signed HTTPS responses with the wrong status/body are target errors, not node failures.
        if ($status > 0) {
            return null;
        }
        // 97 is CURLE_PROXY: PHP does not expose the constant in every supported build.
        return in_array($error, [97, CURLE_OPERATION_TIMEDOUT, CURLE_RECV_ERROR, CURLE_SEND_ERROR,
            CURLE_GOT_NOTHING, CURLE_SSL_CONNECT_ERROR], true) ? false : null;
    }
}
