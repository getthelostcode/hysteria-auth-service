<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserSessionResource\Pages;
use App\Models\UserSession;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** 只读：连接/在线视图（一个 username+addr 一条）。 */
class UserSessionResource extends Resource
{
    protected static ?string $model = UserSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = '用户管理';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = '连接记录';

    protected static ?string $pluralModelLabel = '连接记录';

    public static function canViewAny(): bool
    {
        return auth('admin')->user() !== null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('username')->label('用户')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('addr')->label('客户端地址')->searchable(),
                Tables\Columns\TextColumn::make('online')
                    ->label('状态')
                    ->badge()
                    ->state(fn (UserSession $r): string => $r->isOnline() ? '在线' : '已结束')
                    ->color(fn (UserSession $r): string => $r->isOnline() ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('tx_bytes')->label('上行')->numeric(),
                Tables\Columns\TextColumn::make('rx_bytes')->label('下行')->numeric(),
                Tables\Columns\TextColumn::make('first_seen_at')->label('首次')->since()->toggleable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('最后活跃')->since(),
            ])
            ->filters([
                Tables\Filters\Filter::make('online')
                    ->label('只看在线')
                    ->query(fn (Builder $q): Builder => $q->online()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUserSessions::route('/')];
    }
}
