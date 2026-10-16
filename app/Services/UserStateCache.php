<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * /auth 热路径的用户状态快照：命中缓存就不查库、不算 bcrypt。
 *
 * 注意：任何改动用户状态的管理动作都必须调 forget() —— 这是「禁用秒级生效」的关键，
 * 不能等 TTL 自然过期（旧 FastAPI 版就是纯 TTL，禁用最多要等 60 秒才生效）。
 */
class UserStateCache
{
    public function __construct(
        private readonly AuthCache $cache,
        private readonly SettingStore $settings,
    ) {
    }

    public function ttl(): int
    {
        return max(5, $this->settings->int('auth_cache_ttl'));
    }

    public function get(string $username): ?array
    {
        $raw = $this->cache->conn()->get($this->cache->key('user', $username));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** 缓存未命中 → 查库 → 回填；用户不存在返回 null。 */
    public function resolve(string $username): ?array
    {
        $cached = $this->get($username);
        if ($cached !== null) {
            return $cached;
        }

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            return null;
        }

        return $this->put($user);
    }

    public function put(User $user): array
    {
        $snapshot = self::snapshot($user);
        $this->cache->conn()->setex(
            $this->cache->key('user', $user->username),
            $this->ttl(),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        );

        return $snapshot;
    }

    /** 快照里只放判定需要用到的字段（密码哈希本身就是哈希，放缓存不额外泄露明文）。 */
    public static function snapshot(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => (string) $user->username,
            'password_hash' => (string) $user->password_hash,
            'enabled' => (bool) $user->enabled,
            'expires_at' => $user->expires_at?->getTimestamp(),
            'quota_bytes' => (int) $user->quota_bytes,
            'used_bytes' => (int) $user->used_bytes,
            'max_devices' => (int) $user->max_devices,
        ];
    }

    /** 状态变更 → 立刻失效（用户快照 + 密码判定 + 短期封禁标记）。 */
    public function forget(string $username): void
    {
        $this->cache->forgetUser($username);
    }

    /**
     * 密码校验。开启 password_cache_enabled 时把「这个用户名+这个密码」的结果缓存到 TTL，
     * 省掉每次请求的 bcrypt（CPU 昂贵）；改密码/禁用会连它一起清掉。
     */
    public function verifyPassword(string $username, string $password, string $passwordHash): bool
    {
        if (! $this->settings->bool('password_cache_enabled')) {
            return Hash::check($password, $passwordHash);
        }

        $key = $this->cache->key('pwd', $username, substr(hash('sha256', $password), 0, 24));
        $cached = $this->cache->conn()->get($key);

        if ($cached !== null) {
            return $cached === '1';
        }

        $ok = Hash::check($password, $passwordHash);
        $this->cache->conn()->setex($key, $this->ttl(), $ok ? '1' : '0');

        return $ok;
    }
}
