<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** 日汇总（图表数据源）。`day` 只保留 Y-m-d：date 强转会写成 "Y-m-d 00:00:00"，和查询/图表用的字符串对不上。 */
class TrafficDaily extends Model
{
    protected $table = 'traffic_daily';

    protected $fillable = ['user_id', 'username', 'day', 'tx', 'rx'];

    protected function casts(): array
    {
        return ['tx' => 'integer', 'rx' => 'integer'];
    }

    protected function day(): Attribute
    {
        return Attribute::make(
            get: fn ($value): ?Carbon => $value === null ? null : Carbon::parse((string) $value)->startOfDay(),
            set: fn ($value): ?string => $value === null ? null : Carbon::parse((string) $value)->toDateString(),
        );
    }
}
