<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\OnlineTracker;
use App\Services\SettingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** 用户自助重置自己的 UUID：要重输密码 + 按用户限流。 */
class SelfUuidResetTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'username' => 'alice',
            'password_hash' => Hash::make('secret-pass'),
            'enabled' => true,
        ]);
    }

    /** 以 alice 的身份提重置请求（用户已在用例里建好，别再建一个同名用户）。 */
    private function reset(array $data = [], ?User $as = null)
    {
        $as ??= User::query()->where('username', 'alice')->firstOrFail();

        return $this->actingAs($as, 'web')
            ->post('/me/uuid/reset', array_merge(['current_password' => 'secret-pass'], $data));
    }

    public function test_user_can_reset_their_own_uuid(): void
    {
        $user = $this->user();
        $old = (string) $user->uuid;

        // 先用旧 uuid 验一次（把快照写进 Redis）
        $this->postJson('/api/auth', ['addr' => '203.0.113.9:1', 'auth' => $old, 'tx' => 0])
            ->assertJson(['ok' => true]);

        $response = $this->reset();
        $response->assertRedirect();
        $response->assertSessionHas('uuid_rotated');

        $new = (string) $user->refresh()->uuid;
        $this->assertNotSame($old, $new);

        // 旧的立刻不行，新的立刻能用
        $this->postJson('/api/auth', ['addr' => '203.0.113.9:2', 'auth' => $old, 'tx' => 0])
            ->assertJson(['ok' => false, 'reason' => 'unknown_user']);
        $this->postJson('/api/auth', ['addr' => '203.0.113.9:3', 'auth' => $new, 'tx' => 0])
            ->assertJson(['ok' => true, 'id' => 'alice']);

        $log = AuditLog::query()->where('action', 'user.uuid_self_reset')->latest('id')->firstOrFail();
        $this->assertSame('user', $log->actor_type);
        $this->assertSame('alice', $log->actor_label);
    }

    public function test_reset_requires_the_current_password(): void
    {
        $user = $this->user();
        $old = (string) $user->uuid;

        $this->reset(['current_password' => 'wrong-pass'])->assertSessionHasErrors('current_password');

        $this->assertSame($old, (string) $user->refresh()->uuid, '密码不对就不能换钥匙');
    }

    public function test_reset_is_denied_when_admin_disabled_it(): void
    {
        app(SettingStore::class)->set('user_uuid_reset_enabled', false);
        $user = $this->user();
        $old = (string) $user->uuid;

        $this->reset()->assertStatus(403);

        $this->assertSame($old, (string) $user->refresh()->uuid);
    }

    public function test_reset_is_rate_limited_per_user(): void
    {
        config(['hyauth.self_uuid_reset.per_hour' => 2]);
        $user = $this->user();

        $this->reset()->assertRedirect();
        $this->reset()->assertRedirect();
        $this->reset()->assertSessionHasErrors('current_password');

        $this->assertSame(2, AuditLog::query()->where('action', 'user.uuid_self_reset')->count());
    }

    public function test_optional_disconnect_queues_a_kick(): void
    {
        $user = $this->user();
        app(OnlineTracker::class)->touch('alice', '203.0.113.9:1', '203.0.113.9');

        $this->reset(['disconnect' => 1])->assertRedirect();

        $kicks = app(OnlineTracker::class)->pendingKicks('alice');
        $this->assertNotEmpty($kicks, '勾选“同时断开”后应排入待踢队列');
        $this->assertSame('uuid_reset', $kicks[0]['reason']);
    }

    public function test_guest_cannot_reset(): void
    {
        $this->post('/me/uuid/reset', ['current_password' => 'secret-pass'])->assertRedirect('/login');
    }

    public function test_me_page_shows_the_reset_form(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'web')->get('/me')
            ->assertOk()
            ->assertSee('重置 UUID')
            ->assertSee((string) $user->uuid);
    }
}
