<?php

namespace App\Support;

use RuntimeException;

/** The upstream theme ships a compiled Vue bundle only. Never modify it in place. */
final class DefaultThemeHealthAsset
{
    public const URL = '/assets/external-subscriptions/xboard-dashboard-health-v2.js';

    private const STOCK_SHA256 = '69ff1e68dd44b84f803e631367b6fc9dfd52e79d049067fa52cfa859031f20dc';
    private const DOT = 'Ir("div",{class:J(["h-1.5 w-1.5 rounded-full",e.is_online?"bg-blue-500":"bg-red-500"])},null,2)';
    private const LEGEND = 'oe(e.$t("五分钟内节点在线情况"))';

    public static function isStockFile(string $path): bool
    {
        return is_file($path) && hash_file('sha256', $path) === self::STOCK_SHA256;
    }

    public static function compile(string $source): string
    {
        if (hash('sha256', $source) !== self::STOCK_SHA256
            || substr_count($source, self::DOT) !== 1 || substr_count($source, self::LEGEND) !== 1) {
            throw new RuntimeException('Unsupported stock Xboard dashboard bundle; review the health renderer before building.');
        }

        $imports = 'import {renderExternalHealth as xbRenderHealth, healthLegend as xbHealthLegend} from "/assets/external-subscriptions/node-health-v2.js";';
        return $imports . "\n" . str_replace(
            [self::DOT, self::LEGEND],
            ['(e.external?xbRenderHealth(Ir,e):' . self::DOT . ')', 'oe(t.value.some(n=>n.external)?xbHealthLegend():e.$t("五分钟内节点在线情况"))'],
            $source
        );
    }

    /** Build once into an image-owned path, outside sites' persistent public/theme trees. */
    public static function build(string $root): void
    {
        $source = file_get_contents($root . '/theme/Xboard/assets/umi.js');
        if ($source === false) {
            throw new RuntimeException('Stock Xboard dashboard bundle missing.');
        }
        $compiled = self::compile($source);
        $target = $root . '/public' . self::URL;
        if (file_exists($target)) {
            if (!hash_equals(hash('sha256', $compiled), (string) hash_file('sha256', $target))) {
                throw new RuntimeException('Refusing to overwrite a different generated dashboard asset.');
            }
            return;
        }
        if (file_put_contents($target, $compiled, LOCK_EX) !== strlen($compiled)) {
            throw new RuntimeException('Could not create the generated dashboard asset.');
        }
    }
}
