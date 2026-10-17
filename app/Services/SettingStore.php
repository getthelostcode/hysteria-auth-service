<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * 面板可改的运行时设置。默认值来自 config/hyauth.php（.env 覆盖），
 * 数据库里有记录则以数据库为准；读走缓存，写时清缓存。
 */
class SettingStore
{
    /** 全部可改键与默认值。 */
    public static function defaults(): array
    {
        return [
            // 注册
            'registration_enabled' => (bool) config('hyauth.registration.enabled', false),
            'registration_requires_invite' => (bool) config('hyauth.registration.requires_invite', true),
            'registration_max_per_hour_per_ip' => (int) config('hyauth.registration.max_per_hour_per_ip', 3),
            'min_password_length' => (int) config('hyauth.registration.min_password_length', 8),
            // 新用户默认
            'default_quota_bytes' => (int) config('hyauth.defaults.quota_bytes', 0),
            'default_valid_days' => (int) config('hyauth.defaults.valid_days', 30),
            'default_max_devices' => (int) config('hyauth.defaults.max_devices', 3),
            // 鉴权
            'auth_mode' => (string) config('hyauth.auth_mode', 'both'),   // uuid | password | both
            'auth_cache_ttl' => (int) config('hyauth.cache_ttl', 60),
            'password_cache_enabled' => (bool) config('hyauth.password_cache', false),
            'denial_audit_enabled' => (bool) config('hyauth.audit.log_denials', true),
            'traffic_report_enabled' => false,
            // 品牌
            'brand_name' => 'Hysteria 认证服务',
            'panel_footer' => 'Hysteria Auth Service',
        ];
    }

    public function all(): array
    {
        return Cache::rememberForever($this->cacheKey(), fn (): array => $this->load());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $default ?? (self::defaults()[$key] ?? null);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    public function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            if (! array_key_exists($key, self::defaults())) {
                continue; // 只允许已知键，避免脏数据
            }

            // auth_mode 只允许三个值，写错就退回 both
            if ($key === 'auth_mode' && ! in_array($value, ['uuid', 'password', 'both'], true)) {
                $value = 'both';
            }

            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => ['v' => $value], 'updated_at' => now()],
            );
        }

        $this->forget();
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function load(): array
    {
        $stored = [];

        foreach (Setting::query()->get() as $row) {
            $raw = $row->value;
            $stored[$row->key] = is_array($raw) && array_key_exists('v', $raw) ? $raw['v'] : $raw;
        }

        // 只为已知键取值，类型按默认值归一
        $result = [];
        foreach (self::defaults() as $key => $default) {
            $result[$key] = array_key_exists($key, $stored) ? $this->cast($stored[$key], $default) : $default;
        }

        return $result;
    }

    private function cast(mixed $value, mixed $default): mixed
    {
        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            is_int($default) => (int) $value,
            default => is_scalar($value) ? (string) $value : $default,
        };
    }

    private function cacheKey(): string
    {
        return rtrim((string) config('hyauth.cache_prefix', 'hyauth'), ':').':settings';
    }
}
