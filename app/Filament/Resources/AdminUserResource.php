<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AdminUserResource\Pages;
use App\Models\AdminUser;
use App\Services\AuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** 后台管理员（只有超级管理员能看/能改）。 */
class AdminUserResource extends Resource
{
    protected static ?string $model = AdminUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = '系统';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = '管理员';

    protected static ?string $pluralModelLabel = '管理员与角色';

    public static function canViewAny(): bool
    {
        return (bool) auth('admin')->user()?->isSuperAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('姓名')->required()->maxLength(64),
            Forms\Components\TextInput::make('email')->label('邮箱（登录名）')->email()->required()->maxLength(120),
            Forms\Components\TextInput::make('password')->label('密码')->password()->revealable()
                ->minLength(8)
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create')
                ->helperText('编辑时留空表示不改密码。'),
            Forms\Components\Select::make('role')->label('角色')
                ->options(AdminUser::ROLES)
                ->default(AdminUser::ROLE_OPERATOR)
                ->required()
                ->helperText('运维可管用户/配额/密码；只读只能看。'),
            Forms\Components\Toggle::make('enabled')->label('启用')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('姓名')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('邮箱')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('role')->label('角色')->badge()
                    ->formatStateUsing(fn (AdminUser $r): string => $r->roleLabel()),
                Tables\Columns\IconColumn::make('enabled')->label('启用')->boolean(),
                Tables\Columns\TextColumn::make('last_login_at')->label('最近登录')->since()->placeholder('从未'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (AdminUser $record): bool => $record->id !== auth('admin')->id()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminUsers::route('/'),
            'create' => Pages\CreateAdminUser::route('/create'),
            'edit' => Pages\EditAdminUser::route('/{record}/edit'),
        ];
    }
}
