<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 管理动作 + 鉴权拒绝事件（只增不删）。 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_type', 'actor_id', 'actor_label', 'action',
        'target_type', 'target_id', 'target_label', 'meta', 'ip', 'created_at',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
