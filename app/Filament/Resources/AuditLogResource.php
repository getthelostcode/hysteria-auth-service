<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** 只读：审计日志（管理动作 + 鉴权拒绝，按 (原因, 身份) 10 分钟去重）。 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = '系统';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = '审计日志';

    protected static ?string $pluralModelLabel = '审计日志';

    public static function canViewAny(): bool
    {
        return auth('admin')->user() !== null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('时间')->dateTime('m-d H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('action')->label('动作')->badge()->searchable(),
                Tables\Columns\TextColumn::make('actor_label')->label('操作者')->searchable(),
                Tables\Columns\TextColumn::make('target_label')->label('对象')->searchable(),
                Tables\Columns\TextColumn::make('ip')->label('IP'),
                Tables\Columns\TextColumn::make('meta')->label('详情')->limit(40)->wrap()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label('动作')
                    ->options(fn (): array => AuditLog::query()
                        ->select('action')->distinct()->orderBy('action')->pluck('action', 'action')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
