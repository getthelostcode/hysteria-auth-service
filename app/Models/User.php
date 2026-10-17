<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * Hysteria 认证用户。
 *
 * 凭据有两种（由设置 auth_mode 决定用哪种）：
 *   1. `auth = "<uuid>"`         —— 客户端只填 uuid（推荐，可随时重置）
 *   2. `auth = "用户名:密码"`     —— 传统形态
 * 无论哪种，返回给 Hysteria 的 `id` 都是**用户名**（流量统计按它归类）。
 */
class User extends Authenticatable
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'username', 'uuid', 'password_hash', 'email', 'enabled', 'expires_at',
        'quota_bytes', 'used_bytes', 'tx_bytes', 'rx_bytes', 'max_devices',
        'note', 'last_online_at', 'last_ip', 'disabled_at', 'disabled_reason', 'invite_code_id',
    ];

    protected $hidden = ['password_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'expires_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_online_at' => 'datetime',
            'uuid_rotated_at' => 'datetime',
            'quota_bytes' => 'integer',
            'used_bytes' => 'integer',
            'tx_bytes' => 'integer',
            'rx_bytes' => 'integer',
            'max_devices' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // 任何创建路径（注册 / 后台 / API / 导入）都自动带一个 uuid
        static::creating(function (self $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
                $user->uuid_rotated_at = now();
            }
        });
    }

    /** 自助登录时用 users.password_hash 作为密码列。 */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    // ---------------- 关系 ----------------

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'username', 'username');
    }

    public function dailyTraffic(): HasMany
    {
        return $this->hasMany(TrafficDaily::class, 'username', 'username');
    }

    public function trafficReports(): HasMany
    {
        return $this->hasMany(TrafficReport::class, 'username', 'username');
    }

    // ---------------- 状态判定（/auth 的判据）----------------

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function quotaExceeded(): bool
    {
        return $this->quota_bytes > 0 && $this->used_bytes >= $this->quota_bytes;
    }

    public function isUsable(): bool
    {
        return $this->enabled && ! $this->isExpired() && ! $this->quotaExceeded();
    }

    public function remainingBytes(): ?int
    {
        if ($this->quota_bytes <= 0) {
            return null;
        }

        return max(0, $this->quota_bytes - $this->used_bytes);
    }

    public function quotaPercent(): ?float
    {
        if ($this->quota_bytes <= 0) {
            return null;
        }

        return round(min(100, $this->used_bytes / $this->quota_bytes * 100), 2);
    }

    public function statusLabel(): string
    {
        if (! $this->enabled) {
            return '已禁用';
        }
        if ($this->isExpired()) {
            return '已过期';
        }
        if ($this->quotaExceeded()) {
            return '流量已用完';
        }

        return '正常';
    }

    public function isOnline(): bool
    {
        $ttl = (int) config('hyauth.online_ttl', 90);

        return $this->last_online_at !== null && $this->last_online_at->gt(now()->subSeconds($ttl));
    }

    /** 客户端配置里要填的那一行（按当前 auth_mode 给最合适的形态）。 */
    public function authHint(): string
    {
        $mode = app(\App\Services\SettingStore::class)->string('auth_mode');

        return match ($mode) {
            'password' => $this->username.':<你的密码>',
            'uuid' => (string) $this->uuid,
            default => (string) $this->uuid,   // both：默认推荐 uuid
        };
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    // ---------------- scope ----------------

    public function scopeEnabled(Builder $q): void
    {
        $q->where('enabled', true);
    }

    public function scopeDisabled(Builder $q): void
    {
        $q->where('enabled', false);
    }

    public function scopeExpired(Builder $q): void
    {
        $q->whereNotNull('expires_at')->where('expires_at', '<', now());
    }

    public function scopeOverQuota(Builder $q): void
    {
        $q->where('quota_bytes', '>', 0)->whereColumn('used_bytes', '>=', 'quota_bytes');
    }

    public function scopeOnline(Builder $q): void
    {
        $q->where('last_online_at', '>=', now()->subSeconds((int) config('hyauth.online_ttl', 90)));
    }
}
