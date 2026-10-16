<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\TrafficDaily;
use App\Models\User;
use App\Services\OnlineTracker;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '20s';

    protected function getStats(): array
    {
        $online = app(OnlineTracker::class);
        $today = TrafficDaily::query()->where('day', now()->toDateString())->get();

        $total = User::query()->count();
        $enabled = User::query()->enabled()->count();
        $disabled = User::query()->disabled()->count();
        $expired = User::query()->expired()->count();
        $overQuota = User::query()->overQuota()->count();

        return [
            Stat::make('在线用户', (string) $online->onlineCount())
                ->description('90 秒内活跃')
                ->color('success'),

            Stat::make('用户总数', (string) $total)
                ->description("启用 {$enabled} · 禁用 {$disabled}")
                ->color('primary'),

            Stat::make('今日流量', self::human((int) $today->sum('tx') + (int) $today->sum('rx')))
                ->description('上行 '.self::human((int) $today->sum('tx')).' · 下行 '.self::human((int) $today->sum('rx')))
                ->color('info'),

            Stat::make('需要处理', (string) ($expired + $overQuota))
                ->description("已过期 {$expired} · 流量用完 {$overQuota}")
                ->color($expired + $overQuota > 0 ? 'danger' : 'gray'),
        ];
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $value >= 10 || $i <= 1 ? 0 : 2).' '.$units[$i];
    }
}
