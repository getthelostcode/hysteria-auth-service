<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Hysteria 认证用户：`username` 就是 Hysteria 侧的 `id`（登录时 userid:password 的 userid）。
 *
 * 本模型同时用于用户自助面（/me）的登录；后台管理员见 AdminUser。
 */
class User extends Authenticatable
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'username', 'password_hash', 'email', 'enabled', 'expires_at',
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
            'quota_bytes' => 'integer',
            'used_bytes' => 'integer',
            'tx_bytes' => 'integer',
            'rx_bytes' => 'integer',
            'max_devices' => 'integer',
        ];
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

    /** 能否放行：启用 + 未过期 + 未超额。 */
    public function isUsable(): bool
    {
        return $this->enabled && ! $this->isExpired() && ! $this->quotaExceeded();
    }

    public function remainingBytes(): ?int
    {
        if ($this->quota_bytes <= 0) {
            return null; // 不限
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

    /** 已用完配额（quota_bytes > 0 且 used >= quota）。 */
    public function scopeOverQuota(Builder $q): void
    {
        $q->where('quota_bytes', '>', 0)->whereColumn('used_bytes', '>=', 'quota_bytes');
    }

    public function scopeOnline(Builder $q): void
    {
        $q->where('last_online_at', '>=', now()->subSeconds((int) config('hyauth.online_ttl', 90)));
    }
}
