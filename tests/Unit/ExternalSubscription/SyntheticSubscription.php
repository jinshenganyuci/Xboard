<?php

namespace Tests\Unit\ExternalSubscription;

/** Protocol shapes only: all names, endpoints and credentials are synthetic. */
final class SyntheticSubscription
{
    public static function body(): string
    {
        $lines = [];
        $types = ['vless', 'trojan', 'anytls', 'vless', 'trojan', 'anytls', 'vless', 'trojan', 'anytls', 'anytls', 'anytls'];
        foreach ($types as $index => $type) {
            $number = $index + 1;
            $credential = $type === 'vless' ? '11111111-2222-4333-8444-555555555555' : 'synthetic-p@ss:/#?+ %' . $number;
            $params = match ($type) {
                'vless' => ['security' => 'reality', 'type' => 'tcp', 'packetEncoding' => 'none',
                    'sni' => 'tls.example.com', 'fp' => 'safari', 'flow' => 'xtls-rprx-vision',
                    'sid' => 'aabb', 'pbk' => str_repeat('a', 43), 'encryption' => 'none'],
                'trojan' => ['sni' => 'tls.example.com', 'type' => 'tcp', 'fp' => 'safari'],
                'anytls' => ['security' => 'tls', 'type' => 'tcp', 'packetEncoding' => 'none',
                    'sni' => 'tls.example.com', 'udp' => '1'],
            };
            $lines[] = $type . '://' . rawurlencode($credential) . '@node' . $number . '.example.com:443?'
                . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '#Synthetic%20' . $number;
        }
        return implode("\n", $lines);
    }
}
