<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InviteCode;
use App\Models\User;
use App\Services\SettingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $values): void
    {
        app(SettingStore::class)->setMany($values);
    }

    public function test_registration_page_is_404_when_disabled(): void
    {
        $this->settings(['registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
    }

    public function test_open_registration_without_invite_creates_a_usable_user(): void
    {
        $this->settings([
            'registration_enabled' => true,
            'registration_requires_invite' => false,
            'default_quota_bytes' => 1024 ** 3,
            'default_valid_days' => 7,
            'default_max_devices' => 2,
            'min_password_length' => 8,
        ]);

        $response = $this->post('/register', [
            'username' => 'newbie',
            'password' => 'passw0rd!',
            'password_confirmation' => 'passw0rd!',
        ]);

        $response->assertRedirect('/me');

        $user = User::query()->where('username', 'newbie')->firstOrFail();
        $this->assertSame(1024 ** 3, $user->quota_bytes);
        $this->assertSame(2, $user->max_devices);
        $this->assertNotNull($user->expires_at);
        $this->assertTrue($user->expires_at->between(now()->addDays(6), now()->addDays(8)));

        // 注册完就能通过 /auth
        $this->postJson('/api/auth', ['addr' => '203.0.113.1:1', 'auth' => 'newbie:passw0rd!', 'tx' => 0])
            ->assertJson(['ok' => true, 'id' => 'newbie']);
    }

    public function test_invite_is_required_and_consumed_once(): void
    {
        $this->settings([
            'registration_enabled' => true,
            'registration_requires_invite' => true,
            'default_quota_bytes' => 0,
            'default_valid_days' => 30,
        ]);

        $this->post('/register', ['username' => 'invite1', 'password' => 'passw0rd!', 'password_confirmation' => 'passw0rd!'])
            ->assertSessionHasErrors('invite_code');

        $invite = InviteCode::generate(1, 2 * 1024 ** 3, 3);

        $this->post('/register', [
            'username' => 'invite1', 'invite_code' => $invite->code,
            'password' => 'passw0rd!', 'password_confirmation' => 'passw0rd!',
        ])->assertRedirect('/me');

        $this->assertSame(1, $invite->refresh()->uses);
        $this->assertSame(2 * 1024 ** 3, User::query()->where('username', 'invite1')->value('quota_bytes'));

        // 邀请码只有 1 次 → 第二个用户不行
        $this->post('/register', [
            'username' => 'invite2', 'invite_code' => $invite->code,
            'password' => 'passw0rd!', 'password_confirmation' => 'passw0rd!',
        ])->assertSessionHasErrors('invite_code');
    }

    public function test_username_and_password_rules_are_enforced(): void
    {
        $this->settings([
            'registration_enabled' => true,
            'registration_requires_invite' => false,
            'min_password_length' => 10,
        ]);

        $this->post('/register', ['username' => 'ab', 'password' => 'passw0rd!!', 'password_confirmation' => 'passw0rd!!'])
            ->assertSessionHasErrors('username');

        $this->post('/register', ['username' => 'has space', 'password' => 'passw0rd!!', 'password_confirmation' => 'passw0rd!!'])
            ->assertSessionHasErrors('username');

        $this->post('/register', ['username' => 'okname', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        User::query()->create(['username' => 'taken', 'password_hash' => bcrypt('passw0rd!!')]);
        $this->post('/register', ['username' => 'taken', 'password' => 'passw0rd!!', 'password_confirmation' => 'passw0rd!!'])
            ->assertSessionHasErrors('username');
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        $this->settings([
            'registration_enabled' => true,
            'registration_requires_invite' => false,
            'registration_max_per_hour_per_ip' => 2,
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $this->post('/register', [
                'username' => 'user'.$i, 'password' => 'passw0rd!', 'password_confirmation' => 'passw0rd!',
            ])->assertRedirect('/me');
        }

        $this->post('/register', [
            'username' => 'user3', 'password' => 'passw0rd!', 'password_confirmation' => 'passw0rd!',
        ])->assertSessionHasErrors('username');
    }

    public function test_registered_user_can_see_own_usage_and_change_password(): void
    {
        $this->settings(['registration_enabled' => true, 'registration_requires_invite' => false]);

        $this->post('/register', ['username' => 'self', 'password' => 'passw0rd!', 'password_confirmation' => 'passw0rd!']);
        $this->get('/me')->assertOk()->assertSee('self');

        $this->patch('/me/password', [
            'current_password' => 'passw0rd!',
            'password' => 'new-passw0rd!',
            'password_confirmation' => 'new-passw0rd!',
        ])->assertRedirect();

        $this->postJson('/api/auth', ['addr' => '203.0.113.5:1', 'auth' => 'self:new-passw0rd!', 'tx' => 0])
            ->assertJson(['ok' => true]);
        $this->postJson('/api/auth', ['addr' => '203.0.113.5:2', 'auth' => 'self:passw0rd!', 'tx' => 0])
            ->assertJson(['ok' => false, 'reason' => 'bad_password']);
    }

    public function test_disabled_user_cannot_log_into_the_self_service_area(): void
    {
        $this->settings(['registration_enabled' => true, 'registration_requires_invite' => false]);
        $this->post('/register', ['username' => 'gone', 'password' => 'passw0rd!', 'password_confirmation' => 'passw0rd!']);
        $this->post('/logout');

        User::query()->where('username', 'gone')->update(['enabled' => false]);

        $this->post('/login', ['username' => 'gone', 'password' => 'passw0rd!'])
            ->assertSessionHasErrors('username');
    }
}
