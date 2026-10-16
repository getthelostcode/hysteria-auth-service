<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InviteCode extends Model
{
    protected $fillable = ['code', 'max_uses', 'uses', 'expires_at', 'quota_bytes', 'valid_days', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'datetime',
            'quota_bytes' => 'integer',
            'valid_days' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_uses > 0 && $this->uses >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired() && ! $this->isExhausted();
    }

    public function scopeUsable(Builder $q): void
    {
        $q->where(fn (Builder $w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereColumn('uses', '<', 'max_uses');
    }

    public static function generate(int $maxUses = 1, ?int $quotaBytes = null, ?int $validDays = null, string $note = '', ?int $createdBy = null): self
    {
        do {
            $code = 'inv_'.bin2hex(random_bytes(8));
        } while (self::query()->where('code', $code)->exists());

        return self::create(compact('code', 'note', 'createdBy') + [
            'max_uses' => $maxUses,
            'quota_bytes' => $quotaBytes,
            'valid_days' => $validDays,
        ] + ['created_by' => $createdBy]);
    }
}
