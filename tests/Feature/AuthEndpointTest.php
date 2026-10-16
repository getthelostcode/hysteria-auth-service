<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingStore;
use App\Services\UserManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /auth —— Hysteria 的鉴权契约。
 * 最重要的两条：① 拒绝也必须是 200；② 状态改动（禁用/改配额）立刻生效，不等缓存 TTL。
 */
class AuthEndpointTest extends TestCase
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

    public function test_valid_credentials_are_allowed_with_username_as_id(): void
    {
        $this->user();

        $this->auth('alice:secret-pass')
            ->assertStatus(200)
            ->assertJson(['ok' => true, 'id' => 'alice']);
    }

    public function test_every_rejection_is_still_http_200(): void
    {
        $this->user();
        $this->user(['username' => 'bob', 'enabled' => false]);
        $this->user(['username' => 'carol', 'expires_at' => now()->subDay()]);
        $this->user(['username' => 'dave', 'quota_bytes' => 100, 'used_bytes' => 100]);

        foreach ([
            'alice:wrong-password' => 'bad_password',
            'ghost:x' => 'unknown_user',
            'broken' => 'malformed',
            'bob:secret-pass' => 'disabled',
            'carol:secret-pass' => 'expired',
            'dave:secret-pass' => 'quota_exceeded',
        ] as $auth => $reason) {
            $response = $this->auth($auth);
            $response->assertStatus(200);                       // 契约：拒绝也 200
            $this->assertFalse($response->json('ok'), $auth);
            $this->assertSame('', $response->json('id'), $auth);
            $this->assertSame($reason, $response->json('reason'), $auth);
        }
    }

    public function test_disabling_a_user_takes_effect_immediately_even_with_warm_cache(): void
    {
        $user = $this->user();

        // 先成功一次，把用户快照写进 Redis（TTL 60s）
        $this->auth('alice:secret-pass')->assertJson(['ok' => true]);
        $this->assertNotNull(app(\App\Services\UserStateCache::class)->get('alice'));

        // 管理员禁用
        app(UserManager::class)->disable($user, '欠费');

        // 不睡觉、不等 TTL：下一次就必须被拒
        $this->auth('alice:secret-pass')
            ->assertStatus(200)
            ->assertJson(['ok' => false, 'id' => '', 'reason' => 'disabled']);

        // 重新启用也要立刻放行
        app(UserManager::class)->enable($user->refresh());
        $this->auth('alice:secret-pass')->assertJson(['ok' => true]);
    }

    public function test_changing_quota_takes_effect_immediately(): void
    {
        $user = $this->user(['quota_bytes' => 0]);
        $this->auth('alice:secret-pass')->assertJson(['ok' => true]);

        app(UserManager::class)->updateQuota($user, 10);
        $user->forceFill(['used_bytes' => 10])->save();

        $this->auth('alice:secret-pass')->assertJson(['ok' => false, 'reason' => 'quota_exceeded']);
    }

    public function test_quota_counts_unflushed_redis_traffic(): void
    {
        $this->user(['quota_bytes' => 1000]);

        // 600 字节还没落库（在 Redis 计数里）
        $this->auth('alice:secret-pass', '203.0.113.9:1', ['tx' => 600])->assertJson(['ok' => true]);

        // DB 里 used_bytes 还是 0，但判定必须把未落库的 600 算进去
        $this->assertSame(0, User::query()->where('username', 'alice')->value('used_bytes'));
        $this->auth('alice:secret-pass', '203.0.113.9:2', ['tx' => 600])->assertJson(['ok' => true]);

        // 再来 600 → 总计 1200 > 1000，拒绝
        $this->auth('alice:secret-pass', '203.0.113.9:3', ['tx' => 600])
            ->assertJson(['ok' => false, 'reason' => 'quota_exceeded']);
    }

    public function test_traffic_is_counted_as_session_delta_not_cumulative_sum(): void
    {
        $this->user();

        // Hysteria 上报的是「该连接的累计值」：连接时 0，断开时给总量。
        // 同一个 addr 连续上报 3 次，最终只能入账最后一次的量（1000），不是 100+300+1000。
        $this->auth('alice:secret-pass', '198.51.100.7:4444', ['tx' => 0])->assertJson(['ok' => true]);
        $this->auth('alice:secret-pass', '198.51.100.7:4444', ['tx' => 100])->assertJson(['ok' => true]);
        $this->auth('alice:secret-pass', '198.51.100.7:4444', ['tx' => 300])->assertJson(['ok' => true]);
        $this->auth('alice:secret-pass', '198.51.100.7:4444', ['tx' => 1000, 'rx' => 5000])->assertJson(['ok' => true]);

        $this->artisan('traffic:flush')->assertSuccessful();

        $user = User::query()->where('username', 'alice')->first();
        $this->assertSame(6000, $user->used_bytes, '只应入账最终增量 1000+5000');
        $this->assertSame(1000, $user->tx_bytes);
        $this->assertSame(5000, $user->rx_bytes);
    }

    public function test_device_limit_is_enforced_per_ip(): void
    {
        $this->user(['max_devices' => 1]);

        $this->auth('alice:secret-pass', '198.51.100.1:1111')->assertJson(['ok' => true]);
        // 同一 IP 重连不算新设备
        $this->auth('alice:secret-pass', '198.51.100.1:2222')->assertJson(['ok' => true]);
        // 换 IP 就超了
        $this->auth('alice:secret-pass', '198.51.100.2:3333')
            ->assertJson(['ok' => false, 'reason' => 'too_many_devices']);
    }

    public function test_repeated_failures_get_rate_limited(): void
    {
        $this->user();

        for ($i = 0; $i < 20; $i++) {
            $this->auth('alice:bad');
        }

        $this->auth('alice:secret-pass')->assertJson(['ok' => false, 'reason' => 'rate_limited']);
    }

    public function test_password_reset_makes_the_old_password_stop_working(): void
    {
        $user = $this->user();
        $this->auth('alice:secret-pass')->assertJson(['ok' => true]);

        app(UserManager::class)->resetPassword($user, 'brand-new-pass');

        $this->auth('alice:secret-pass')->assertJson(['ok' => false, 'reason' => 'bad_password']);
        $this->auth('alice:brand-new-pass')->assertJson(['ok' => true]);
    }

    public function test_denials_are_audited_once_per_identity(): void
    {
        $this->user();

        $this->auth('alice:bad');
        $this->auth('alice:bad');
        $this->auth('alice:bad');

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.bad_password')->count(),
            '同一身份的同一拒绝 10 分钟内只留一条');
    }

    public function test_denial_audit_can_be_turned_off(): void
    {
        app(SettingStore::class)->set('denial_audit_enabled', false);
        $this->user();

        $this->auth('alice:bad');

        $this->assertSame(0, AuditLog::query()->where('action', 'auth.bad_password')->count());
    }
}
