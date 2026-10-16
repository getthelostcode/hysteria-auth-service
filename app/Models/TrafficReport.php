<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 原始增量账（只记差分）。 */
class TrafficReport extends Model
{
    protected $fillable = ['user_id', 'username', 'addr', 'tx_delta', 'rx_delta', 'source', 'reported_at'];

    protected function casts(): array
    {
        return [
            'tx_delta' => 'integer',
            'rx_delta' => 'integer',
            'reported_at' => 'datetime',
        ];
    }
}
