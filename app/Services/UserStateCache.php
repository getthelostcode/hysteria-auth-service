<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * /auth 热路径的用户状态快照：命中缓存就不查库、不算 bcrypt。
 *
 * 快照挂在两个键上：
 *   hyauth:user:{username}  —— 用户名/密码形态查它
 *   hyauth:uuid:{uuid}      —— uuid 形态查它
 * 内容相同；任何状态变更都必须同时失效（UserStateCache::forget / UserManager）。
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
        return $this->decode($this->cache->conn()->get($this->cache->key('user', $username)));
    }

    public function getByUuid(string $uuid): ?array
    {
        return $this->decode($this->cache->conn()->get($this->cache->key('uuid', $uuid)));
    }

    /** 用户名形态：缓存未命中 → 查库 → 回填；不存在返回 null。 */
    public function resolve(string $username): ?array
    {
        $cached = $this->get($username);
        if ($cached !== null) {
            return $cached;
        }

        $user = User::query()->where('username', $username)->first();

        return $user === null ? null : $this->put($user);
    }

    /** uuid 形态：同上。 */
    public function resolveByUuid(string $uuid): ?array
    {
        $cached = $this->getByUuid($uuid);
        if ($cached !== null) {
            return $cached;
        }

        $user = User::query()->where('uuid', $uuid)->first();

        return $user === null ? null : $this->put($user);
    }

    public function put(User $user): array
    {
        $snapshot = self::snapshot($user);
        $payload = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $conn = $this->cache->conn();
        $ttl = $this->ttl();

        $conn->setex($this->cache->key('user', $user->username), $ttl, $payload);

        if (! empty($user->uuid)) {
            $conn->setex($this->cache->key('uuid', (string) $user->uuid), $ttl, $payload);
        }

        return $snapshot;
    }

    /** 快照只放判定需要的字段（密码哈希本身就是哈希）。 */
    public static function snapshot(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => (string) $user->username,
            'uuid' => (string) $user->uuid,
            'password_hash' => (string) $user->password_hash,
            'enabled' => (bool) $user->enabled,
            'expires_at' => $user->expires_at?->getTimestamp(),
            'quota_bytes' => (int) $user->quota_bytes,
            'used_bytes' => (int) $user->used_bytes,
            'max_devices' => (int) $user->max_devices,
        ];
    }

    /** 状态变更 → 立刻失效（用户名键 + uuid 键 + 密码判定 + 短期封禁）。 */
    public function forget(string $username, ?string $uuid = null): void
    {
        $this->cache->forgetUser($username, $uuid);
    }

    /**
     * 密码校验（只用于 用户名:密码 形态）。
     * 开启 password_cache_enabled 时缓存「这个用户名+这个密码」的结果，省 bcrypt。
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

    private function decode(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
