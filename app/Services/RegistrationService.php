<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * 用户自助注册。开关、是否必须邀请码、默认配额/有效期都能在面板里改。
 */
class RegistrationService
{
    public const USERNAME_PATTERN = '/^[a-zA-Z0-9_][a-zA-Z0-9_.-]{2,31}$/';

    public function __construct(
        private readonly SettingStore $settings,
        private readonly AuthCache $cache,
        private readonly AuditLogger $audit,
        private readonly UserStateCache $states,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('registration_enabled');
    }

    public function requiresInvite(): bool
    {
        return $this->settings->bool('registration_requires_invite');
    }

    /**
     * @param  array{username:string,password:string,email?:?string,invite_code?:?string}  $input
     * @return array{user:User,invite:?InviteCode}
     */
    public function register(array $input, ?string $ip): array
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['username' => '当前未开放注册。']);
        }

        $this->assertWithinRateLimit($ip);
        $this->assertUsernameAvailable($input['username']);
        $this->assertPasswordStrong($input['password']);

        $invite = $this->resolveInvite($input['invite_code'] ?? null);

        $quota = $invite?->quota_bytes ?? $this->settings->int('default_quota_bytes');
        $days = $invite?->valid_days ?? $this->settings->int('default_valid_days');

        $user = User::query()->create([
            'username' => $input['username'],
            'password_hash' => Hash::make($input['password']),
            'email' => $input['email'] ?? null,
            'enabled' => true,
            'expires_at' => $days > 0 ? now()->addDays($days) : null,
            'quota_bytes' => max(0, (int) $quota),
            'max_devices' => $this->settings->int('default_max_devices'),
            'invite_code_id' => $invite?->id,
            'note' => '自助注册',
        ]);

        if ($invite !== null) {
            $invite->increment('uses');
        }

        $this->states->forget($user->username);
        $this->audit->log('user.registered', $user, [
            'invite_code' => $invite?->code,
            'quota_bytes' => $user->quota_bytes,
            'expires_at' => $user->expires_at?->toIso8601String(),
        ], $ip);

        return ['user' => $user, 'invite' => $invite];
    }

    public function assertUsernameAvailable(string $username): void
    {
        if (! preg_match(self::USERNAME_PATTERN, $username)) {
            throw ValidationException::withMessages([
                'username' => '用户名只能包含字母、数字、下划线、点、短横线，长度 3-32，且以字母/数字/下划线开头。',
            ]);
        }

        if (User::withTrashed()->where('username', $username)->exists()) {
            throw ValidationException::withMessages(['username' => '这个用户名已被占用。']);
        }
    }

    private function assertPasswordStrong(string $password): void
    {
        $min = max(6, $this->settings->int('min_password_length'));

        if (mb_strlen($password) < $min) {
            throw ValidationException::withMessages(['password' => "密码至少 {$min} 位。"]);
        }
    }

    private function assertWithinRateLimit(?string $ip): void
    {
        $max = max(1, $this->settings->int('registration_max_per_hour_per_ip'));
        $key = $this->cache->key('reg', (string) ($ip ?? 'unknown'));

        $count = (int) $this->cache->conn()->incr($key);
        if ($count === 1) {
            $this->cache->conn()->expire($key, 3600);
        }

        if ($count > $max) {
            throw ValidationException::withMessages([
                'username' => '注册过于频繁，请稍后再试（每小时最多 '.$max.' 次）。',
            ]);
        }
    }

    private function resolveInvite(?string $code): ?InviteCode
    {
        $code = $code !== null ? trim($code) : '';

        if ($code === '') {
            if ($this->requiresInvite()) {
                throw ValidationException::withMessages(['invite_code' => '当前注册需要邀请码。']);
            }

            return null;
        }

        $invite = InviteCode::query()->where('code', $code)->first();

        if ($invite === null || ! $invite->isUsable()) {
            throw ValidationException::withMessages(['invite_code' => '邀请码无效、已用完或已过期。']);
        }

        return $invite;
    }
}
