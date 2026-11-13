<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * 不需要去除前后空格的字段名
     * @var array<int, string>
     */
    protected $except = [
        'password',
        'password_confirmation',
        'encrypted_data',
        'signature'
    ];

    protected function transform($key, $value)
    {
        // Global DNS credentials are opaque values. Preserve their exact bytes so
        // validation can reject control characters instead of silently removing them.
        // Existing node-specific cert_config.dns_env fields keep their original behavior.
        if (str_starts_with($key, 'dns_env.')) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
