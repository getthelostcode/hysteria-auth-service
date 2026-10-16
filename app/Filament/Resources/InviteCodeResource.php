<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\InviteCodeResource\Pages;
use App\Models\InviteCode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InviteCodeResource extends Resource
{
    protected static ?string $model = InviteCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = '用户管理';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = '邀请码';

    protected static ?string $pluralModelLabel = '邀请码';

    public static function canViewAny(): bool
    {
        return auth('admin')->user() !== null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('max_uses')->label('可用次数')->numeric()->default(1)->required(),
            Forms\Components\TextInput::make('quota_bytes')
                ->label('注册后配额（字节，留空=用面板默认）')
                ->numeric(),
            Forms\Components\TextInput::make('valid_days')->label('注册后有效期（天，留空=用面板默认）')->numeric(),
            Forms\Components\DateTimePicker::make('expires_at')->label('邀请码本身到期时间')->seconds(false),
            Forms\Components\TextInput::make('note')->label('备注')->maxLength(200),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('邀请码')->copyable()->searchable(),
                Tables\Columns\TextColumn::make('uses')->label('已用 / 上限')
                    ->state(fn (InviteCode $r): string => $r->uses.' / '.($r->max_uses > 0 ? $r->max_uses : '不限'))
                    ->badge(),
                Tables\Columns\TextColumn::make('quota_bytes')->label('配额')->placeholder('默认')
                    ->state(fn (InviteCode $r): ?string => $r->quota_bytes !== null ? UserResource::human($r->quota_bytes) : null),
                Tables\Columns\TextColumn::make('valid_days')->label('有效期(天)')->placeholder('默认'),
                Tables\Columns\TextColumn::make('expires_at')->label('邀请码到期')->dateTime('Y-m-d')->placeholder('不限'),
                Tables\Columns\TextColumn::make('usable')->label('状态')->badge()
                    ->state(fn (InviteCode $r): string => $r->isUsable() ? '可用' : '不可用')
                    ->color(fn (InviteCode $r): string => $r->isUsable() ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('note')->label('备注')->limit(20)->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('创建时间')->since()->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('copyLink')
                    ->label('注册链接')
                    ->icon('heroicon-o-link')
                    ->action(function (InviteCode $record): void {
                        Notification::make()
                            ->title('带邀请码的注册链接')
                            ->body(url('/register?invite='.$record->code))
                            ->persistent()
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInviteCodes::route('/'),
            'create' => Pages\CreateInviteCode::route('/create'),
        ];
    }
}
