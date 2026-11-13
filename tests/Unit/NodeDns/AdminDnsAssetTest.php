<?php

namespace Tests\Unit\NodeDns;

use App\Support\AdminDnsAsset;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AdminDnsAssetTest extends TestCase
{
    public function test_adapter_build_preserves_original_assets_and_their_resolution_base(): void
    {
        $root = sys_get_temp_dir() . '/xboard-admin-dns-' . bin2hex(random_bytes(8));
        $source = $root . '/public' . AdminDnsAsset::SOURCE;
        $target = $root . '/public' . AdminDnsAsset::URL;
        mkdir(dirname($source), 0700, true);
        copy(dirname(__DIR__, 3) . '/public' . AdminDnsAsset::SOURCE, $source);
        try {
            $original = hash_file('sha256', $source);
            $this->assertTrue(AdminDnsAsset::isStockFile($source));
            AdminDnsAsset::build($root);
            $compiled = file_get_contents($target);
            $this->assertStringContainsString('from "/assets/node-dns/node-dns-form-v1.js"', $compiled);
            $this->assertStringContainsString('new URL("' . AdminDnsAsset::SOURCE . '",window.location.origin)', $compiled);
            $this->assertStringNotContainsString('import.meta.url', $compiled);
            $this->assertStringContainsString('qL(xbDnsPayload(', $compiled);
            $this->assertStringContainsString('use_global_dns:uy().optional()', $compiled);
            $this->assertSame($original, hash_file('sha256', $source));
            AdminDnsAsset::build($root);
            $this->assertSame($compiled, file_get_contents($target));

            file_put_contents($source, '/* custom build */', FILE_APPEND);
            $this->assertFalse(AdminDnsAsset::isStockFile($source));
            $this->expectException(RuntimeException::class);
            AdminDnsAsset::build($root);
        } finally {
            unlink($source);
            if (is_file($target)) unlink($target);
            foreach (['/public/assets/admin/assets', '/public/assets/admin', '/public/assets/node-dns', '/public/assets', '/public', ''] as $directory) {
                if (is_dir($root . $directory)) rmdir($root . $directory);
            }
        }
    }

    public function test_adapter_refuses_unknown_or_changed_stock_input(): void
    {
        $this->expectException(RuntimeException::class);
        AdminDnsAsset::compile('/* unknown admin */');
    }
}
