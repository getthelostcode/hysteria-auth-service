<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 一个 (username, addr) 一条连接记录：在线设备数、踢人都靠它。 */
class UserSession extends Model
{
    protected $fillable = [
        'user_id', 'username', 'addr', 'ip', 'tx_bytes', 'rx_bytes',
        'first_seen_at', 'last_seen_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'tx_bytes' => 'integer',
            'rx_bytes' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOnline(): bool
    {
        return $this->ended_at === null
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds((int) config('hyauth.online_ttl', 90)));
    }

    public function scopeOnline(Builder $q): void
    {
        $q->whereNull('ended_at')
            ->where('last_seen_at', '>=', now()->subSeconds((int) config('hyauth.online_ttl', 90)));
    }
}
