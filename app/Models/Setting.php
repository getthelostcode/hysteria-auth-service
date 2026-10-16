<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 面板可改的运行时设置（key/value，value 是 JSON）。读写统一走 App\Services\SettingStore。 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value', 'updated_at'];

    protected function casts(): array
    {
        return ['value' => 'array', 'updated_at' => 'datetime'];
    }
}
