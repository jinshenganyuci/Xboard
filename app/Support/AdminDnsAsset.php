<?php

namespace App\Support;

use RuntimeException;

/** Extend the pinned stock admin without modifying the distributed upstream files. */
final class AdminDnsAsset
{
    public const URL = '/assets/node-dns/admin-dns-v1.js';
    public const SOURCE = '/assets/admin/assets/index-CEIYH7i8.js';
    private const STOCK_SHA256 = 'f04f09a95bfdb04fa5132e336bf87c364d4e360cb95774872ff18eb69e9bcd77';

    public static function isStockFile(string $path): bool
    {
        return is_file($path) && hash_file('sha256', $path) === self::STOCK_SHA256;
    }

    public static function compile(string $source): string
    {
        if (hash('sha256', $source) !== self::STOCK_SHA256) {
            throw new RuntimeException('Unsupported stock admin bundle; review the DNS form adapter before building.');
        }
        $replacements = [
            // Keep this marker in parsed protocol certificate values as well as the top-level form.
            'py({cert_mode:_y(["http","dns","self","file","none"])' =>
                'py({use_global_dns:uy().optional(),cert_mode:_y(["http","dns","self","file","none"])',
            'g=`${t}.key_content`;return Q.jsxs(Q.Fragment,{children:[' =>
                'g=`${t}.key_content`;const xbDns=xbUseDnsDefaults(H,e,t,n,signal=>IL(OL()+"/dns-settings/fetch",{signal}),r);return Q.jsxs(Q.Fragment,{children:[',
            's("dynamic_form.shadowsocks.cert_config.cert_mode.description")]})]})}),("http"===n||"dns"===n||"self"===n||"content"===n)' =>
                's("dynamic_form.shadowsocks.cert_config.cert_mode.description")]})]})}),xbDns.panel,("http"===n||"dns"===n||"self"===n||"content"===n)',
            '("http"===n||"dns"===n)&&Q.jsx($y,{control:e.control,name:l,' =>
                '("http"===n||"dns"===n&&!xbDns.usingGlobal)&&Q.jsx($y,{control:e.control,name:l,',
            '"dns"===n&&Q.jsxs("div",{className:"space-y-4",children:[Q.jsx($y,{control:e.control,name:c,' =>
                '"dns"===n&&!xbDns.usingGlobal&&Q.jsxs("div",{className:"space-y-4",children:[Q.jsx($y,{control:e.control,name:c,',
            'E=(t="cert")=>{let i=t;' => 'E=(t="cert")=>{xbDnsBegin(e,d);let i=t;',
            'Q.jsx(htt,{open:r,onOpenChange:s,children:Q.jsx(ptt,{className:"max-w-2xl gap-0' =>
                'Q.jsx(htt,{open:r,onOpenChange:xbOpen=>{if(!xbOpen)xbDnsCancel(e);s(xbOpen)},children:Q.jsx(ptt,{className:"max-w-2xl gap-0',
            'onClick:()=>s(!1),className:"font-mono text-xs",children:i("common.cancel","Cancel")' =>
                'onClick:()=>{xbDnsCancel(e);s(!1)},className:"font-mono text-xs",children:i("common.cancel","Cancel")',
            'return!t})()&&s(!1)},className:"px-8 font-mono text-xs",children:i("common.save","Save")' =>
                'return!t})()&&(xbDnsCommit(e),s(!1))},className:"px-8 font-mono text-xs",children:i("common.save","Save")',
            'qL({...s,type:l,transfer_enable:i})' => 'qL(xbDnsPayload({...s,type:l,transfer_enable:i}))',
        ];
        foreach ($replacements as $from => $to) {
            if (substr_count($source, $from) !== 1) {
                throw new RuntimeException('Stock admin DNS adapter anchor mismatch.');
            }
            $source = str_replace($from, $to, $source);
        }
        // Monaco workers and lazy assets must keep resolving beside the original admin entry.
        if (substr_count($source, 'import.meta.url') !== 133) {
            throw new RuntimeException('Stock admin asset base changed.');
        }
        $source = str_replace('import.meta.url', 'xbAdminSourceURL', $source);
        return 'import {useDnsDefaults as xbUseDnsDefaults, beginDnsEdit as xbDnsBegin, cancelDnsEdit as xbDnsCancel, commitDnsEdit as xbDnsCommit, nodeDnsPayload as xbDnsPayload} from "/assets/node-dns/node-dns-form-v1.js";' . "\n"
            . 'const xbAdminSourceURL=new URL("' . self::SOURCE . '",window.location.origin).href;' . "\n" . $source;
    }

    public static function build(string $root): void
    {
        $source = @file_get_contents($root . '/public' . self::SOURCE);
        if ($source === false) {
            throw new RuntimeException('Stock admin bundle missing.');
        }
        $compiled = self::compile($source);
        $target = $root . '/public' . self::URL;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Could not create the admin DNS asset directory.');
        }
        if (file_exists($target)) {
            if (!hash_equals(hash('sha256', $compiled), (string) hash_file('sha256', $target))) {
                throw new RuntimeException('Refusing to overwrite a different generated admin DNS asset.');
            }
            return;
        }
        if (file_put_contents($target, $compiled, LOCK_EX) !== strlen($compiled)) {
            throw new RuntimeException('Could not create the generated admin DNS asset.');
        }
    }
}
