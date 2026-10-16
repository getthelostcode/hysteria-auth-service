<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\User;
use App\Services\SettingStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 后台管理员（面板 /admin）；普通用户只能自助注册或由管理员创建
        AdminUser::query()->firstOrCreate(
            ['email' => (string) env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => 'Hysteria Admin',
                'password' => (string) env('ADMIN_PASSWORD', 'password'),
                'role' => AdminUser::ROLE_SUPER_ADMIN,
                'enabled' => true,
            ],
        );

        if (app()->environment('local')) {
            AdminUser::query()->firstOrCreate(
                ['email' => 'operator@example.com'],
                ['name' => 'Ops Operator', 'password' => 'password', 'role' => AdminUser::ROLE_OPERATOR],
            );
            AdminUser::query()->firstOrCreate(
                ['email' => 'viewer@example.com'],
                ['name' => 'Read Only', 'password' => 'password', 'role' => AdminUser::ROLE_VIEWER],
            );

            // 本机演示用户（生产不会建）
            if (! User::query()->where('username', 'demo')->exists()) {
                User::query()->create([
                    'username' => 'demo',
                    'password_hash' => Hash::make('demo12345'),
                    'email' => 'demo@example.com',
                    'enabled' => true,
                    'expires_at' => now()->addDays(30),
                    'quota_bytes' => 10 * 1024 ** 3,
                    'max_devices' => 3,
                    'note' => '演示账号',
                ]);
            }
        }

        // 把当前默认值落成显式设置，方便面板里直接看到
        app(SettingStore::class)->setMany([
            'registration_enabled' => (bool) config('hyauth.registration.enabled', false),
            'registration_requires_invite' => (bool) config('hyauth.registration.requires_invite', true),
            'default_quota_bytes' => (int) config('hyauth.defaults.quota_bytes', 0),
            'default_valid_days' => (int) config('hyauth.defaults.valid_days', 30),
            'default_max_devices' => (int) config('hyauth.defaults.max_devices', 3),
        ]);

        $this->command?->info('管理员：'.env('ADMIN_EMAIL', 'admin@example.com').' / '.(string) env('ADMIN_PASSWORD', 'password'));
    }
}
