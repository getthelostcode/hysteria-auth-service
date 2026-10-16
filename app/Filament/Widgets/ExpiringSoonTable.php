<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** 3 天内到期 / 流量用完的用户 —— 面板第一眼要看到的东西。 */
class ExpiringSoonTable extends TableWidget
{
    protected static ?string $heading = '即将到期 / 流量用完（3 天内）';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->where(fn ($q) => $q
                        ->where(fn ($w) => $w->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(3)))
                        ->orWhere(fn ($w) => $w->where('quota_bytes', '>', 0)->whereColumn('used_bytes', '>=', 'quota_bytes')))
                    ->orderBy('expires_at')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('username')->label('用户'),
                Tables\Columns\TextColumn::make('status')->label('状态')
                    ->badge()
                    ->state(fn (User $r): string => $r->statusLabel())
                    ->color(fn (User $r): string => $r->quotaExceeded() ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('expires_at')->label('到期')->dateTime('Y-m-d H:i')->placeholder('不限'),
                Tables\Columns\TextColumn::make('used_bytes')->label('已用 / 配额')
                    ->state(fn (User $r): string => $r->quota_bytes > 0
                        ? round($r->used_bytes / 1073741824, 2).' / '.round($r->quota_bytes / 1073741824, 2).' GiB'
                        : round($r->used_bytes / 1073741824, 2).' GiB / 不限'),
            ])
            ->paginated(false);
    }
}
