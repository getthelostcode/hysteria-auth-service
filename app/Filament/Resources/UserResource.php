<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\OnlineTracker;
use App\Services\RegistrationService;
use App\Services\TrafficService;
use App\Services\UserManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = '用户管理';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = '用户';

    protected static ?string $pluralModelLabel = '用户';

    protected static ?string $recordTitleAttribute = 'username';

    public static function getNavigationBadge(): ?string
    {
        $online = app(OnlineTracker::class)->onlineCount();

        return $online > 0 ? (string) $online.' 在线' : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('账号')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('username')
                        ->label('用户名（= Hysteria 的 id）')
                        ->required()
                        ->maxLength(32)
                        ->rule('regex:'.RegistrationService::USERNAME_PATTERN)
                        ->disabledOn('edit')
                        ->dehydrated()
                        ->helperText('创建后不可修改：客户端用它作为 用户名:密码 的账号部分。'),

                    Forms\Components\TextInput::make('uuid')
                        ->label('UUID（客户端凭据）')
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit')
                        ->helperText('要换一把：回列表点该行的「重置 UUID」。旧 UUID 重置后立刻失效。'),

                    Forms\Components\TextInput::make('email')
                        ->label('邮箱（可选）')
                        ->email()
                        ->maxLength(120),

                    Forms\Components\TextInput::make('password')
                        ->label('密码')
                        ->password()
                        ->revealable()
                        ->minLength(6)
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create')
                        ->helperText('留空则不改动；编辑时留空表示保持原密码。'),

                    Forms\Components\Toggle::make('enabled')
                        ->label('启用')
                        ->default(true)
                        ->helperText('关闭即立刻生效：下一次 /auth 就会被拒（不用等缓存过期）。'),
                ]),

            Forms\Components\Section::make('配额与有效期')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('quota_bytes')
                        ->label('流量配额（字节，0=不限）')
                        ->numeric()
                        ->default(0)
                        ->helperText('1 GiB = 1073741824；填 0 表示不限量。'),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('到期时间（留空=不过期）')
                        ->seconds(false),

                    Forms\Components\TextInput::make('max_devices')
                        ->label('同时在线设备数（按 IP，0=不限）')
                        ->numeric()
                        ->default(0),

                    Forms\Components\TextInput::make('note')
                        ->label('备注')
                        ->maxLength(200),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('用户名')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('uuid')
                    ->label('UUID')
                    ->copyable()
                    ->copyMessage('UUID 已复制')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->state(fn (User $record): string => $record->statusLabel())
                    ->color(fn (User $record): string => match ($record->statusLabel()) {
                        '正常' => 'success',
                        '已禁用' => 'danger',
                        '已过期' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\ToggleColumn::make('enabled')
                    ->label('启用')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->beforeStateUpdated(function (User $record, $state): void {
                        $manager = app(UserManager::class);
                        $state ? $manager->enable($record) : $manager->disable($record, '面板切换');
                    }),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('到期')
                    ->dateTime('Y-m-d')
                    ->placeholder('不限')
                    ->color(fn (User $record): string => $record->isExpired() ? 'danger' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('traffic')
                    ->label('已用 / 配额')
                    ->state(function (User $record): string {
                        $used = self::human($record->used_bytes);
                        if ($record->quota_bytes <= 0) {
                            return $used.' / 不限';
                        }

                        return $used.' / '.self::human($record->quota_bytes).'（'.$record->quotaPercent().'%）';
                    })
                    ->color(fn (User $record): string => $record->quotaExceeded() ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('devices')
                    ->label('在线设备')
                    ->state(fn (User $record): int => app(OnlineTracker::class)->deviceCount($record->username))
                    ->badge()
                    ->color(fn (int $state, User $record): string => $record->max_devices > 0 && $state >= $record->max_devices ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('last_online_at')
                    ->label('最后在线')
                    ->since()
                    ->placeholder('从未')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_ip')
                    ->label('最后 IP')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('note')
                    ->label('备注')
                    ->limit(24)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->options([
                        'enabled' => '已启用',
                        'disabled' => '已禁用',
                        'expired' => '已过期',
                        'over_quota' => '流量已用完',
                        'online' => '在线',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'enabled' => $query->enabled(),
                            'disabled' => $query->disabled(),
                            'expired' => $query->expired(),
                            'over_quota' => $query->overQuota(),
                            'online' => $query->online(),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('disable')
                    ->label('禁用')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record): bool => $record->enabled && (bool) auth('admin')->user()?->canManageUsers())
                    ->form([
                        Forms\Components\TextInput::make('reason')->label('原因（记入审计）')->maxLength(200),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('禁用该用户')
                    ->modalDescription('立刻生效：缓存被主动清掉，下一次 /auth 就会被拒绝；在线会话会进待踢队列。')
                    ->action(function (User $record, array $data): void {
                        app(UserManager::class)->disable($record, (string) ($data['reason'] ?? ''));
                        Notification::make()->title('已禁用 '.$record->username)->success()->send();
                    }),

                Tables\Actions\Action::make('enable')
                    ->label('启用')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record): bool => ! $record->enabled && (bool) auth('admin')->user()?->canManageUsers())
                    ->action(function (User $record): void {
                        app(UserManager::class)->enable($record);
                        Notification::make()->title('已启用 '.$record->username)->success()->send();
                    }),

                Tables\Actions\Action::make('resetUuid')
                    ->label('重置 UUID')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->requiresConfirmation()
                    ->modalHeading('重置该用户的 UUID')
                    ->modalDescription('客户端要用新 UUID 重新配置；旧 UUID 立刻失效（正在连的会话不受影响，下次重连就被拒）。')
                    ->action(function (User $record): void {
                        $new = app(UserManager::class)->resetUuid($record);
                        Notification::make()
                            ->title('新 UUID（旧的下一次请求即失效）')
                            ->body($new)
                            ->persistent()
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('resetPassword')
                    ->label('重置密码')
                    ->icon('heroicon-o-key')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->form([
                        Forms\Components\TextInput::make('password')->label('新密码（留空=随机生成）')->minLength(6),
                    ])
                    ->action(function (User $record, array $data): void {
                        $plain = app(UserManager::class)->resetPassword($record, $data['password'] ?: null);
                        Notification::make()
                            ->title('新密码（只显示一次）')
                            ->body($plain)
                            ->persistent()
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('addQuota')
                    ->label('加流量')
                    ->icon('heroicon-o-plus-circle')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->form([
                        Forms\Components\TextInput::make('add_gib')->label('增加（GiB）')->numeric()->required()->default(10),
                    ])
                    ->action(function (User $record, array $data): void {
                        $add = (int) round(((float) $data['add_gib']) * 1024 ** 3);
                        app(UserManager::class)->updateQuota($record, max(0, $record->quota_bytes + $add));
                        Notification::make()->title('配额已更新')->success()->send();
                    }),

                Tables\Actions\Action::make('extend')
                    ->label('续期')
                    ->icon('heroicon-o-calendar-days')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->form([
                        Forms\Components\TextInput::make('days')->label('延长天数')->numeric()->required()->default(30),
                    ])
                    ->action(function (User $record, array $data): void {
                        $base = $record->expires_at !== null && $record->expires_at->isFuture() ? $record->expires_at : now();
                        app(UserManager::class)->updateQuota($record, null, $base->copy()->addDays((int) $data['days']));
                        Notification::make()->title('有效期已延长')->success()->send();
                    }),

                Tables\Actions\Action::make('resetUsage')
                    ->label('流量归零')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        app(UserManager::class)->resetUsage($record);
                        Notification::make()->title('已归零')->success()->send();
                    }),

                Tables\Actions\Action::make('kick')
                    ->label('踢下线')
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->requiresConfirmation()
                    ->modalDescription('把该用户排入待踢队列；真正断开由节点侧执行（或由控制面调用 hysteria 本地 API）。')
                    ->action(function (User $record): void {
                        app(UserManager::class)->kick($record, null, 'panel');
                        Notification::make()->title('已排入待踢队列')->success()->send();
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers()),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('enableBulk')
                    ->label('批量启用')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->action(function ($records): void {
                        $manager = app(UserManager::class);
                        foreach ($records as $record) {
                            $manager->enable($record);
                        }
                        Notification::make()->title('已启用 '.$records->count().' 个用户')->success()->send();
                    }),

                Tables\Actions\BulkAction::make('disableBulk')
                    ->label('批量禁用')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->form([Forms\Components\TextInput::make('reason')->label('原因')])
                    ->action(function ($records, array $data): void {
                        $manager = app(UserManager::class);
                        foreach ($records as $record) {
                            $manager->disable($record, (string) ($data['reason'] ?? ''));
                        }
                        Notification::make()->title('已禁用 '.$records->count().' 个用户')->success()->send();
                    }),

                Tables\Actions\BulkAction::make('addQuotaBulk')
                    ->label('批量加流量')
                    ->icon('heroicon-o-plus-circle')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->form([Forms\Components\TextInput::make('add_gib')->label('每人增加（GiB）')->numeric()->required()->default(10)])
                    ->action(function ($records, array $data): void {
                        $manager = app(UserManager::class);
                        $add = (int) round(((float) $data['add_gib']) * 1024 ** 3);
                        foreach ($records as $record) {
                            $manager->updateQuota($record, max(0, $record->quota_bytes + $add));
                        }
                        Notification::make()->title('已为 '.$records->count().' 个用户加流量')->success()->send();
                    }),

                Tables\Actions\BulkAction::make('extendBulk')
                    ->label('批量续期')
                    ->icon('heroicon-o-calendar-days')
                    ->visible(fn (): bool => (bool) auth('admin')->user()?->canManageUsers())
                    ->form([Forms\Components\TextInput::make('days')->label('延长天数')->numeric()->required()->default(30)])
                    ->action(function ($records, array $data): void {
                        $manager = app(UserManager::class);
                        foreach ($records as $record) {
                            $base = $record->expires_at !== null && $record->expires_at->isFuture() ? $record->expires_at : now();
                            $manager->updateQuota($record, null, $base->copy()->addDays((int) $data['days']));
                        }
                        Notification::make()->title('已续期 '.$records->count().' 个用户')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
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

    /** 给详情页用：把在线设备与未落库用量一起带上。 */
    public static function detail(User $user): array
    {
        $online = app(OnlineTracker::class);
        $traffic = app(TrafficService::class);

        return [
            'devices' => $online->devices($user->username),
            'pending' => $traffic->pending($user->username),
        ];
    }
}
