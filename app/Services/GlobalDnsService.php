<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\GlobalDnsSetting;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class GlobalDnsService
{
    public function metadata(): array
    {
        try {
            return $this->safeMetadata(GlobalDnsSetting::findOrFail(1));
        } catch (Throwable) {
            throw new ApiException('无法读取全局 DNS 配置，请检查数据库和原 APP_KEY。', 503);
        }
    }

    private function safeMetadata(GlobalDnsSetting $setting): array
    {
        $hasCredentials = is_array($setting->dns_env) && $setting->dns_env !== [];
        return [
            'configured' => $hasCredentials && $setting->email !== '' && $setting->dns_provider !== '',
            'dns_provider' => $setting->dns_provider,
            'email' => $setting->email,
            'has_dns_env' => $hasCredentials,
            'updated_at' => $setting->revision > 0 ? $setting->updated_at?->toIso8601String() : null,
            'revision' => $setting->revision,
        ];
    }

    /** Credentials are validated without placing their keys or values into error messages. */
    public function save(array $data): array
    {
        $environment = $data['dns_env'] ?? [];
        $this->validateEnvironment($environment);
        try {
            return DB::transaction(function () use ($data, $environment) {
                $setting = GlobalDnsSetting::whereKey(1)->lockForUpdate()->firstOrFail();
                if ($setting->revision !== (int) $data['revision']) {
                    throw new ApiException('全局 DNS 配置已被其他管理员修改，请重新读取后再保存。', 409);
                }
                if ($environment === [] && (!$setting->dns_env || $setting->dns_provider !== $data['dns_provider'])) {
                    throw ValidationException::withMessages(['dns_env' => '首次配置或切换 DNS 提供商时，请填写该提供商的 API 环境变量。']);
                }
                $setting->dns_provider = $data['dns_provider'];
                $setting->email = $data['email'];
                if ($environment !== []) {
                    $setting->dns_env = $environment;
                }
                $setting->revision++;
                $setting->updated_at = now();
                $changed = GlobalDnsSetting::whereKey(1)->where('revision', $data['revision'])->update($setting->getDirty());
                if ($changed !== 1) {
                    throw new ApiException('全局 DNS 配置已被其他管理员修改，请重新读取后再保存。', 409);
                }
                DB::afterCommit(fn () => $this->notifyBoundNodes());
                return $this->safeMetadata($setting);
            });
        } catch (ValidationException | ApiException $error) {
            throw $error;
        } catch (Throwable) {
            // SQL and encryption exceptions may contain credentials or ciphertext.
            throw new ApiException('保存全局 DNS 配置失败，请检查数据库和原 APP_KEY。', 503);
        }
    }

    private function validateEnvironment(array $environment): void
    {
        $encoded = json_encode($environment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || count($environment) > 64 || strlen($encoded) > 16384) {
            throw ValidationException::withMessages(['dns_env' => 'DNS 环境变量最多 64 项，总大小不能超过 16 KiB。']);
        }
        foreach ($environment as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $key)
                || !is_string($value) || trim($value) === '' || strlen($value) > 4096
                || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw ValidationException::withMessages(['dns_env' => 'DNS 环境变量名称或值无效：名称须为字母、数字、下划线且不能以数字开头；每项值须为非空单行文本，最多 4096 字节。']);
            }
        }
    }

    public static function usesGlobal(?array $config): bool
    {
        return in_array($config['use_global_dns'] ?? false, [true, 1, '1'], true)
            && ($config['cert_mode'] ?? $config['mode'] ?? null) === 'dns';
    }

    /** Normalize only nodes that opt in. Old independent JSON remains byte-for-byte unchanged. */
    public function normalizeNode(array $attributes, ?Server $existing = null): array
    {
        if (!array_key_exists('cert_config', $attributes)) {
            return $attributes;
        }
        $old = $existing?->cert_config;
        $wasGlobal = self::usesGlobal($old);
        $config = $attributes['cert_config'];
        if ($config === null || $config === []) {
            if ($wasGlobal) {
                $attributes['cert_config'] = $old;
            }
            return $attributes;
        }
        $explicit = array_key_exists('use_global_dns', $config);
        $global = $explicit ? (bool) $config['use_global_dns'] : $wasGlobal;
        $mode = $config['cert_mode'] ?? $config['mode'] ?? ($wasGlobal ? 'dns' : null);
        if ($mode !== 'dns') {
            if ($explicit && $global) {
                throw ValidationException::withMessages(['cert_config' => '只有 DNS-01 证书模式可以使用全局 DNS 配置。']);
            }
            unset($config['use_global_dns']);
            $attributes['cert_config'] = $config;
            return $attributes;
        }
        if (!$global) {
            if ($wasGlobal && $explicit) {
                $this->validateIndependentCertificate($config);
            }
            unset($config['use_global_dns']);
            $attributes['cert_config'] = $config;
            return $attributes;
        }
        if (!$this->metadata()['configured']) {
            throw ValidationException::withMessages(['cert_config' => '请先保存全局 DNS 配置，再让节点使用它。']);
        }
        $domain = $config['domain'] ?? ($wasGlobal ? ($old['domain'] ?? null) : null);
        if (!is_string($domain) || !$this->validDomain($domain)) {
            throw ValidationException::withMessages(['cert_config' => '请为此节点填写有效的证书域名；不能使用 IP 地址或网址。']);
        }
        $attributes['cert_config'] = ['cert_mode' => 'dns', 'domain' => $domain, 'use_global_dns' => true];
        return $attributes;
    }

    /** Opting out of an inherited DNS account requires a usable replacement. */
    private function validateIndependentCertificate(array $config): void
    {
        $message = '切换为独立 DNS 配置时，请填写有效的 DNS 提供商、通知邮箱和非空 API 环境变量。';
        if (Validator::make($config, [
            'dns_provider' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,63}$/D'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'dns_env' => ['required', 'array', 'min:1', 'max:64'],
        ])->fails()) {
            throw ValidationException::withMessages(['cert_config' => $message]);
        }
        try {
            $this->validateEnvironment($config['dns_env']);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['cert_config' => $message]);
        }
    }

    private function validDomain(string $domain): bool
    {
        $name = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;
        return strlen($domain) <= 253 && str_contains($name, '.') && $name === trim($name)
            && !str_ends_with($name, '.') && !filter_var($name, FILTER_VALIDATE_IP)
            && (bool) filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    /** Only authenticated node config delivery may call this credential-bearing method. */
    public function resolveNode(array $config): array
    {
        if (!self::usesGlobal($config)) {
            unset($config['use_global_dns']);
            return $config;
        }
        try {
            $setting = GlobalDnsSetting::findOrFail(1);
            if (!$this->safeMetadata($setting)['configured']) {
                throw new ApiException('节点引用的全局 DNS 配置不可用。', 503);
            }
            return [
                'cert_mode' => 'dns', 'domain' => $config['domain'],
                'email' => $setting->email, 'dns_provider' => $setting->dns_provider,
                'dns_env' => $setting->dns_env,
            ];
        } catch (Throwable) {
            throw new ApiException('节点引用的全局 DNS 配置不可用，请检查配置和原 APP_KEY。', 503);
        }
    }

    private function notifyBoundNodes(): void
    {
        try {
            Server::where('cert_config->use_global_dns', true)->select(['id', 'cert_config'])->chunkById(100, function ($nodes) {
                foreach ($nodes as $node) {
                    if (self::usesGlobal($node->cert_config)) {
                        try {
                            NodeSyncService::notifyConfigUpdated($node->id);
                        } catch (Throwable) {
                            // HTTP pull still obtains the committed config. No exception content is logged.
                            Log::warning('Global DNS node refresh deferred to the next node pull.', ['node_id' => $node->id]);
                        }
                    }
                }
            });
        } catch (Throwable) {
            Log::warning('Global DNS refresh notifications deferred to the next node pull.');
        }
    }
}
