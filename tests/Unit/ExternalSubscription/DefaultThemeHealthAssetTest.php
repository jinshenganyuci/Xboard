<?php

namespace Tests\Unit\ExternalSubscription;

use App\Support\DefaultThemeHealthAsset;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DefaultThemeHealthAssetTest extends TestCase
{
    public function test_build_is_repeatable_without_modifying_the_stock_or_custom_bundle(): void
    {
        $root = sys_get_temp_dir() . '/xboard-theme-' . bin2hex(random_bytes(8));
        $source = $root . '/theme/Xboard/assets/umi.js';
        $target = $root . '/public' . DefaultThemeHealthAsset::URL;
        mkdir(dirname($source), 0700, true);
        mkdir(dirname($target), 0700, true);
        copy(dirname(__DIR__, 3) . '/theme/Xboard/assets/umi.js', $source);
        try {
            $original = hash_file('sha256', $source);
            $this->assertTrue(DefaultThemeHealthAsset::isStockFile($source));
            DefaultThemeHealthAsset::build($root);
            $compiled = hash_file('sha256', $target);
            $this->assertNotSame($original, $compiled);
            $this->assertStringContainsString('from "/assets/external-subscriptions/node-health-v2.js"', file_get_contents($target));
            $this->assertFileExists($root . '/public/assets/external-subscriptions/xboard-dashboard-health-v2.js');
            DefaultThemeHealthAsset::build($root);
            $this->assertSame($compiled, hash_file('sha256', $target));
            $this->assertSame($original, hash_file('sha256', $source));

            file_put_contents($source, '/* site-specific theme */', FILE_APPEND);
            $this->assertFalse(DefaultThemeHealthAsset::isStockFile($source));
            $this->expectException(RuntimeException::class);
            DefaultThemeHealthAsset::build($root);
        } finally {
            unlink($source);
            if (is_file($target)) {
                unlink($target);
            }
            foreach (['/theme/Xboard/assets', '/theme/Xboard', '/theme', '/public/assets/external-subscriptions', '/public/assets', '/public', ''] as $dir) {
                rmdir($root . $dir);
            }
        }
    }
}
