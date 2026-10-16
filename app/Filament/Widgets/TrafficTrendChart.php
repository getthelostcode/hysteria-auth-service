<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\TrafficDaily;
use Filament\Widgets\ChartWidget;

/** 全网最近 7 天上下行流量（数据来自 traffic_daily，flutter 每分钟汇总）。 */
class TrafficTrendChart extends ChartWidget
{
    protected static ?string $heading = '最近 7 天流量';

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $days = collect(range(6, 0))->map(fn (int $i) => now()->subDays($i)->toDateString());

        $rows = TrafficDaily::query()
            ->whereIn('day', $days->all())
            ->selectRaw('day, SUM(tx) as tx, SUM(rx) as rx')
            ->groupBy('day')
            ->pluck(null, 'day');

        return [
            'datasets' => [
                [
                    'label' => '上行 (MiB)',
                    'data' => $days->map(fn (string $day) => round(((int) ($rows[$day]->tx ?? 0)) / 1048576, 2))->all(),
                    'borderColor' => '#f59e0b',
                ],
                [
                    'label' => '下行 (MiB)',
                    'data' => $days->map(fn (string $day) => round(((int) ($rows[$day]->rx ?? 0)) / 1048576, 2))->all(),
                    'borderColor' => '#3b82f6',
                ],
            ],
            'labels' => $days->map(fn (string $day) => substr($day, 5))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
