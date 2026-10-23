<?php

namespace App\Services\ExternalSubscription;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;
use Throwable;

class Fetcher
{
    public function fetch(string $url): string
    {
        try {
            for ($redirect = 0; $redirect <= 3; $redirect++) {
                $target = $this->validateTarget($url);
                $response = $this->request($url, $target);
                if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                    if ($redirect === 3 || empty($response['location'])) {
                        throw new RuntimeException();
                    }
                    $next = (string) UriResolver::resolve(new Uri($url), new Uri($response['location']));
                    if (str_starts_with($url, 'https://') && !str_starts_with($next, 'https://')) {
                        throw new RuntimeException();
                    }
                    $url = $next;
                    continue;
                }
                if ($response['status'] !== 200 || strlen($response['body']) > Parser::MAX_BYTES) {
                    throw new RuntimeException();
                }
                return $response['body'];
            }
        } catch (Throwable $e) {
            // Do not chain exceptions: cURL/HTTP errors can contain a subscription secret.
            throw new RuntimeException('无法获取订阅：请检查公网地址、响应大小、TLS 证书和网络连接。');
        }
        throw new RuntimeException('订阅跳转次数超出限制。');
    }

    protected function validateTarget(string $url): array
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new RuntimeException();
        }
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException();
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ((!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) || str_ends_with($host, '.localhost') || $host === 'localhost') {
            throw new RuntimeException();
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^(?:[0-9.]+|0x[0-9a-f]+)$/iD', $host)) {
            throw new RuntimeException();
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException();
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveAddresses($host);
        if (!$addresses) {
            throw new RuntimeException();
        }
        foreach ($addresses as $address) {
            if (!$this->isPublicAddress($address)) {
                throw new RuntimeException();
            }
        }
        return ['host' => $host, 'port' => $port, 'address' => $addresses[0]];
    }

    protected function resolveAddresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_unique(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: []))));
    }

    protected function isPublicAddress(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $binary = inet_pton($address);
        if (strlen($binary) === 16) {
            // Only ordinary global unicast; reject mapped IPv4, NAT64, Teredo and 6to4.
            return (ord($binary[0]) & 0xe0) === 0x20
                && substr($binary, 0, 4) !== hex2bin('20010000')
                && substr($binary, 0, 2) !== hex2bin('2002');
        }
        $first = ord($binary[0]);
        $second = ord($binary[1]);
        return !($first === 100 && $second >= 64 && $second <= 127)
            && !($first === 192 && $second === 0 && in_array(ord($binary[2]), [0, 2], true))
            && !($first === 192 && $second === 88 && ord($binary[2]) === 99)
            && !($first === 198 && in_array($second, [18, 19], true))
            && !($first === 198 && $second === 51 && ord($binary[2]) === 100)
            && !($first === 203 && $second === 0 && ord($binary[2]) === 113)
            && $first < 224;
    }

    protected function request(string $url, array $target): array
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException();
        }
        $body = '';
        $location = null;
        $overLimit = false;
        $address = str_contains($target['address'], ':') ? '[' . $target['address'] . ']' : $target['address'];
        try {
            curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => filter_var($target['host'], FILTER_VALIDATE_IP) ? [] : [$target['host'] . ':' . $target['port'] . ':' . $address],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => 'gzip,deflate',
                // Request the common URI subscription format; some upstreams reject unknown UAs.
                CURLOPT_USERAGENT => 'v2rayN/7.0',
                CURLOPT_HTTPHEADER => ['Accept: text/plain, application/yaml, text/yaml'],
                CURLOPT_HEADERFUNCTION => function ($handle, string $header) use (&$location, &$overLimit) {
                    if (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, 9));
                    }
                    if (stripos($header, 'Content-Length:') === 0 && (float) trim(substr($header, 15)) > Parser::MAX_BYTES) {
                        $overLimit = true;
                        return 0;
                    }
                    return strlen($header);
                },
                // cURL supplies decompressed bytes, bounding compressed response bombs too.
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$overLimit) {
                    if (strlen($body) + strlen($chunk) > Parser::MAX_BYTES) {
                        $overLimit = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $result = curl_exec($handle);
            if ($result === false || $overLimit) {
                throw new RuntimeException();
            }
            return ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'location' => $location, 'body' => $body];
        } finally {
            curl_close($handle);
        }
    }
}
