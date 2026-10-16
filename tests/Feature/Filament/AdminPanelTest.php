<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = AdminUser::ROLE_SUPER_ADMIN): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Panel Admin',
            'email' => $role.'@panel.test',
            'password' => 'admin-passw0rd',
            'role' => $role,
            'enabled' => true,
        ]);
    }

    private function user(): User
    {
        return User::query()->create([
            'username' => 'alice',
            'password_hash' => Hash::make('secret-pass'),
            'email' => 'alice@example.com',
            'enabled' => true,
            'quota_bytes' => 10 * 1024 ** 3,
            'used_bytes' => 1024 ** 3,
        ]);
    }

    public function test_panel_pages_render_for_super_admin(): void
    {
        $this->user();
        InviteCode::generate(3);

        $admin = $this->admin();

        foreach ([
            '/admin',
            '/admin/users',
            '/admin/users/create',
            '/admin/invite-codes',
            '/admin/user-sessions',
            '/admin/audit-logs',
            '/admin/admin-users',
            '/admin/manage-settings',
        ] as $path) {
            $this->actingAs($admin, 'admin')->get($path)->assertOk();
        }
    }

    public function test_user_list_shows_status_and_traffic(): void
    {
        $this->user();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('alice')
            ->assertSee('正常');
    }

    public function test_hysteria_user_cannot_access_the_panel(): void
    {
        $user = $this->user();

        // users 表里的用户不是面板身份：被 Filament 的 admin guard 挡在登录页
        $this->actingAs($user, 'web')->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_disabled_admin_cannot_access_the_panel(): void
    {
        $admin = $this->admin();
        $admin->update(['enabled' => false]);

        $this->actingAs($admin->refresh(), 'admin')->get('/admin')->assertStatus(403);
    }

    public function test_viewer_cannot_open_settings_or_admin_management(): void
    {
        $viewer = $this->admin(AdminUser::ROLE_VIEWER);

        $this->actingAs($viewer, 'admin')->get('/admin/users')->assertOk();
        $this->actingAs($viewer, 'admin')->get('/admin/manage-settings')->assertStatus(403);
        $this->actingAs($viewer, 'admin')->get('/admin/admin-users')->assertStatus(403);
    }

    public function test_settings_page_saves_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin/manage-settings')->assertOk();

        \Livewire\Livewire::actingAs($admin, 'admin')
            ->test(\App\Filament\Pages\ManageSettings::class)
            ->fillForm([
                'registration_enabled' => true,
                'default_quota_bytes' => 2048,
                'default_valid_days' => 5,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(\App\Services\SettingStore::class);
        $this->assertTrue($settings->bool('registration_enabled'));
        $this->assertSame(2048, $settings->int('default_quota_bytes'));
        $this->assertTrue(AuditLog::query()->where('action', 'settings.updated')->exists());
    }

    public function test_create_user_from_the_panel_makes_it_auth_able(): void
    {
        $admin = $this->admin();

        \Livewire\Livewire::actingAs($admin, 'admin')
            ->test(\App\Filament\Resources\UserResource\Pages\CreateUser::class)
            ->fillForm([
                'username' => 'panel-made',
                'password' => 'passw0rd!',
                'enabled' => true,
                'quota_bytes' => 1024,
                'max_devices' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->postJson('/api/auth', ['addr' => '203.0.113.9:1', 'auth' => 'panel-made:passw0rd!', 'tx' => 0])
            ->assertJson(['ok' => true, 'id' => 'panel-made']);
    }
}
