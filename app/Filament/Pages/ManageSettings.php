<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\AuditLogger;
use App\Services\AuthCache;
use App\Services\SettingStore;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * 面板设置：注册开关、默认配额/有效期、鉴权缓存、拒答审计、品牌。
 * 这些值存在 settings 表，改完立刻生效（服务端读的是同一份缓存）。
 */
class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = '系统';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = '面板设置';

    protected static string $view = 'filament.pages.manage-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth('admin')->user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(app(SettingStore::class)->all());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('注册')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('registration_enabled')
                            ->label('开放自助注册')
                            ->helperText('关闭后 /register 直接 404。'),
                        Forms\Components\Toggle::make('registration_requires_invite')
                            ->label('注册必须携带邀请码'),
                        Forms\Components\TextInput::make('registration_max_per_hour_per_ip')
                            ->label('同 IP 每小时最多注册次数')->numeric()->minValue(1),
                        Forms\Components\TextInput::make('min_password_length')
                            ->label('密码最小长度')->numeric()->minValue(6),
                    ]),

                Forms\Components\Section::make('新用户默认值')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('default_quota_bytes')
                            ->label('默认配额（字节，0=不限）')->numeric()->minValue(0),
                        Forms\Components\TextInput::make('default_valid_days')
                            ->label('默认有效期（天，0=不过期）')->numeric()->minValue(0),
                        Forms\Components\TextInput::make('default_max_devices')
                            ->label('默认同时在线设备数（0=不限）')->numeric()->minValue(0),
                    ]),

                Forms\Components\Section::make('鉴权')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('auth_cache_ttl')
                            ->label('用户状态缓存 TTL（秒）')
                            ->numeric()->minValue(5)
                            ->helperText('禁用/改配额会主动清缓存，所以 TTL 只影响“外部直接改库”的情形。'),
                        Forms\Components\Toggle::make('password_cache_enabled')
                            ->label('缓存密码校验结果（省 bcrypt CPU，注意安全权衡）'),
                        Forms\Components\Toggle::make('denial_audit_enabled')
                            ->label('记录鉴权拒绝事件（按 (原因,身份) 10 分钟去重）'),
                        Forms\Components\Toggle::make('traffic_report_enabled')
                            ->label('开启 /traffic_report 兼容上报（需在 .env 配 TRAFFIC_REPORT_TOKEN）'),
                    ]),

                Forms\Components\Section::make('品牌')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('brand_name')->label('面板/站点名称')->maxLength(64),
                        Forms\Components\TextInput::make('panel_footer')->label('页脚文案')->maxLength(120),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SettingStore $settings): void
    {
        $settings->setMany($this->form->getState());
        app(AuditLogger::class)->log('settings.updated', null, ['keys' => array_keys($this->form->getState())]);

        Notification::make()->title('设置已保存并立即生效')->success()->send();
        $this->form->fill($settings->all());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('flushCache')
                ->label('清空鉴权缓存')
                ->icon('heroicon-o-trash')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('删掉所有 hyauth:* 键（用户快照、在线集合、计数器、限流）。在线状态与未落库的流量计数会丢失，请先跑一次 traffic:flush。')
                ->action(function (AuthCache $cache): void {
                    $deleted = $cache->flushPrefix();
                    Notification::make()->title("已清理 {$deleted} 个键")->success()->send();
                }),
        ];
    }
}
