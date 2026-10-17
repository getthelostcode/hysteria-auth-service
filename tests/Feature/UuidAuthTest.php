<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingStore;
use App\Services\UserManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UUID 作为 Hysteria 的 auth 凭据：客户端只填一串 uuid；管理员可以重置（旧的立刻失效）。
 */
class UuidAuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'username' => 'alice',
            'password_hash' => Hash::make('secret-pass'),
            'enabled' => true,
            'quota_bytes' => 0,
            'max_devices' => 0,
        ], $overrides));
    }

    private function auth(string $auth, string $addr = '203.0.113.9:5555', array $extra = [])
    {
        return $this->postJson('/api/auth', array_merge(['addr' => $addr, 'auth' => $auth, 'tx' => 0], $extra));
    }

    private function mode(string $mode): void
    {
        app(SettingStore::class)->set('auth_mode', $mode);
    }

    public function test_every_new_user_gets_a_uuid(): void
    {
        $user = $this->user();

        $this->assertNotEmpty($user->uuid);
        $this->assertTrue(User::isUuid((string) $user->uuid));
        $this->assertNotNull($user->uuid_rotated_at);
    }

    public function test_auth_with_uuid_returns_the_username_as_id(): void
    {
        $user = $this->user();

        $this->auth((string) $user->uuid)
            ->assertStatus(200)
            ->assertJson(['ok' => true, 'id' => 'alice']);
    }

    public function test_uuid_is_case_insensitive(): void
    {
        $user = $this->user();

        $this->auth(strtoupper((string) $user->uuid))->assertJson(['ok' => true, 'id' => 'alice']);
    }

    public function test_resetting_uuid_kills_the_old_one_immediately(): void
    {
        $user = $this->user();
        $old = (string) $user->uuid;

        // 先用旧 uuid 成功一次（把快照写进 Redis）
        $this->auth($old)->assertJson(['ok' => true]);
        $this->assertNotNull(app(\App\Services\UserStateCache::class)->getByUuid($old));

        $new = app(UserManager::class)->resetUuid($user->refresh());

        $this->assertNotSame($old, $new);
        $this->assertTrue(User::isUuid($new));

        // 不等 TTL：旧钥匙立刻无效
        $this->auth($old)->assertStatus(200)->assertJson(['ok' => false, 'id' => '', 'reason' => 'unknown_user']);
        // 新钥匙立即可用
        $this->auth($new)->assertJson(['ok' => true, 'id' => 'alice']);

        $this->assertTrue(AuditLog::query()->where('action', 'user.uuid_reset')->exists());
    }

    public function test_disabling_a_user_stops_uuid_auth_immediately(): void
    {
        $user = $this->user();
        $uuid = (string) $user->uuid;

        $this->auth($uuid)->assertJson(['ok' => true]);
        app(UserManager::class)->disable($user, '欠费');

        $this->auth($uuid)->assertJson(['ok' => false, 'reason' => 'disabled']);
    }

    public function test_quota_and_device_rules_apply_to_uuid_form(): void
    {
        $user = $this->user(['quota_bytes' => 100, 'max_devices' => 1]);
        $uuid = (string) $user->uuid;

        $this->auth($uuid, '203.0.113.1:1')->assertJson(['ok' => true]);
        // 换 IP 超设备数
        $this->auth($uuid, '203.0.113.2:2')->assertJson(['ok' => false, 'reason' => 'too_many_devices']);

        // 配额用完
        $user->forceFill(['used_bytes' => 100])->save();
        app(\App\Services\UserStateCache::class)->forget($user->username, $uuid);
        $this->auth($uuid, '203.0.113.1:3')->assertJson(['ok' => false, 'reason' => 'quota_exceeded']);
    }

    public function test_both_mode_accepts_password_form_too(): void
    {
        $this->mode('both');
        $this->user();

        $this->auth('alice:secret-pass')->assertJson(['ok' => true, 'id' => 'alice']);
    }

    public function test_uuid_only_mode_rejects_password_form(): void
    {
        $this->mode('uuid');
        $this->user();

        $this->auth('alice:secret-pass')
            ->assertStatus(200)
            ->assertJson(['ok' => false, 'reason' => 'password_disabled']);
    }

    public function test_password_only_mode_rejects_uuid(): void
    {
        $user = $this->user();
        $this->mode('password');

        $this->auth((string) $user->uuid)
            ->assertStatus(200)
            ->assertJson(['ok' => false, 'reason' => 'uuid_disabled']);
    }

    public function test_a_bare_non_uuid_string_is_malformed(): void
    {
        $this->user();

        $this->auth('not-a-uuid')->assertJson(['ok' => false, 'reason' => 'malformed']);
        $this->auth('alice')->assertJson(['ok' => false, 'reason' => 'malformed']);
    }

    public function test_admin_api_resets_uuid(): void
    {
        $user = $this->user();
        $old = (string) $user->uuid;
        $admin = AdminUser::query()->create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => 'admin-passw0rd',
            'role' => AdminUser::ROLE_SUPER_ADMIN, 'enabled' => true,
        ]);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'a@b.test', 'password' => 'admin-passw0rd'])
            ->assertOk()->json('token');

        $response = $this->withToken($token)->postJson('/api/v1/users/alice/uuid/reset')->assertOk();
        $new = $response->json('uuid');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $response->json('data.uuid'));

        $this->auth($old)->assertJson(['ok' => false]);
        $this->auth($new)->assertJson(['ok' => true, 'id' => 'alice']);
    }

    public function test_registration_generates_a_working_uuid(): void
    {
        app(SettingStore::class)->setMany([
            'registration_enabled' => true,
            'registration_requires_invite' => false,
        ]);

        $this->post('/register', [
            'username' => 'selfuser', 'password' => 'passw0rd!', 'password_confirmation' => 'passw0rd!',
        ])->assertRedirect('/me');

        $uuid = (string) User::query()->where('username', 'selfuser')->value('uuid');
        $this->assertTrue(User::isUuid($uuid));

        $this->auth($uuid)->assertJson(['ok' => true, 'id' => 'selfuser']);
    }

    public function test_me_page_shows_the_uuid(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'web')->get('/me')
            ->assertOk()
            ->assertSee((string) $user->uuid);
    }
}
