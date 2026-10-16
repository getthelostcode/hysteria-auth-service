<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = AdminUser::ROLE_SUPER_ADMIN): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'Admin',
            'email' => $role.'@example.com',
            'password' => 'admin-passw0rd',
            'role' => $role,
            'enabled' => true,
        ]);
    }

    private function tokenFor(AdminUser $admin): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email, 'password' => 'admin-passw0rd',
        ])->assertOk()->json('token');
    }

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'username' => 'alice',
            'password_hash' => Hash::make('secret-pass'),
            'enabled' => true,
        ], $overrides));
    }

    public function test_login_returns_token_and_me_returns_role(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', AdminUser::ROLE_SUPER_ADMIN);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->admin();

        $this->postJson('/api/v1/auth/login', ['email' => 'super-admin@example.com', 'password' => 'nope'])
            ->assertStatus(422);
    }

    public function test_unauthenticated_requests_are_401(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
    }

    public function test_disable_via_api_stops_auth_immediately(): void
    {
        $this->user();
        $token = $this->tokenFor($this->admin());

        // 先成功一次（把快照写进 Redis）
        $this->postJson('/api/auth', ['addr' => '203.0.113.9:1', 'auth' => 'alice:secret-pass', 'tx' => 0])
            ->assertJson(['ok' => true]);

        $this->withToken($token)->postJson('/api/v1/users/alice/disable', ['reason' => '欠费'])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->postJson('/api/auth', ['addr' => '203.0.113.9:2', 'auth' => 'alice:secret-pass', 'tx' => 0])
            ->assertJson(['ok' => false, 'reason' => 'disabled']);

        $this->assertTrue(AuditLog::query()->where('action', 'user.disabled')->exists());
    }

    public function test_quota_and_expiry_can_be_updated_and_usage_reset(): void
    {
        $this->user(['used_bytes' => 500, 'tx_bytes' => 500]);
        $token = $this->tokenFor($this->admin());

        $this->withToken($token)->patchJson('/api/v1/users/alice', [
            'quota_bytes' => 1024, 'max_devices' => 2,
        ])->assertOk()->assertJsonPath('data.quota_bytes', 1024);

        $this->withToken($token)->postJson('/api/v1/users/alice/quota/reset')
            ->assertOk()
            ->assertJsonPath('data.used_bytes', 0);
    }

    public function test_password_reset_returns_plaintext_once_and_rotates(): void
    {
        $this->user();
        $token = $this->tokenFor($this->admin());

        $response = $this->withToken($token)->postJson('/api/v1/users/alice/password')->assertOk();
        $plain = $response->json('password');
        $this->assertIsString($plain);
        $this->assertGreaterThanOrEqual(12, strlen($plain));

        $this->postJson('/api/auth', ['addr' => '203.0.113.9:1', 'auth' => 'alice:'.$plain, 'tx' => 0])
            ->assertJson(['ok' => true]);
        $this->postJson('/api/auth', ['addr' => '203.0.113.9:2', 'auth' => 'alice:secret-pass', 'tx' => 0])
            ->assertJson(['ok' => false]);
    }

    public function test_viewer_role_cannot_write(): void
    {
        $this->user();
        $token = $this->tokenFor($this->admin(AdminUser::ROLE_VIEWER));

        $this->withToken($token)->getJson('/api/v1/users')->assertOk();
        $this->withToken($token)->postJson('/api/v1/users/alice/disable')->assertStatus(403);
        $this->withToken($token)->postJson('/api/v1/users', ['username' => 'x', 'password' => 'passw0rd!'])->assertStatus(403);
    }

    public function test_operator_can_manage_users(): void
    {
        $this->user();
        $token = $this->tokenFor($this->admin(AdminUser::ROLE_OPERATOR));

        $this->withToken($token)->postJson('/api/v1/users/alice/disable')->assertOk();
    }

    public function test_stats_endpoint_reports_the_expected_numbers(): void
    {
        $this->user();
        $this->user(['username' => 'bob', 'enabled' => false]);
        $token = $this->tokenFor($this->admin());

        $this->withToken($token)->getJson('/api/v1/stats')
            ->assertOk()
            ->assertJsonPath('data.users_total', 2)
            ->assertJsonPath('data.users_enabled', 1)
            ->assertJsonPath('data.users_disabled', 1);
    }

    public function test_traffic_report_endpoint_requires_token_and_records_deltas(): void
    {
        config(['hyauth.traffic_report_token' => 'shared-secret']);
        app(\App\Services\SettingStore::class)->set('traffic_report_enabled', true);
        $this->user();

        $this->postJson('/api/traffic_report', ['user_id' => 'alice', 'tx_delta' => 10, 'rx_delta' => 20])
            ->assertStatus(401);

        $this->withHeader('X-Auth-Token', 'shared-secret')
            ->postJson('/api/traffic_report', ['user_id' => 'alice', 'tx_delta' => 10, 'rx_delta' => 20])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->artisan('traffic:flush')->assertSuccessful();
        $this->assertSame(30, (int) User::query()->where('username', 'alice')->value('used_bytes'));
    }

    public function test_health_endpoint_reports_dependencies(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'ok', 'database' => 'ok', 'redis' => 'ok']);
    }
}
