<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 失败计数与短期封禁：防用户名/密码爆破，也防「配额用完」的用户把 /auth 打成热点。
 */
class FailureTracker
{
    public function __construct(private readonly AuthCache $cache)
    {
    }

    private function window(): int
    {
        return 60;
    }

    public function threshold(): int
    {
        return max(1, (int) config('hyauth.max_failures_per_min', 20));
    }

    public function blockSeconds(): int
    {
        return max(1, (int) config('hyauth.fail_block_seconds', 60));
    }

    public function record(string $username, ?string $ip): void
    {
        $conn = $this->cache->conn();
        $ttl = $this->window();

        foreach (array_filter([$ip ? $this->cache->key('fail', 'ip', $ip) : null, $this->cache->key('fail', 'user', $username)]) as $key) {
            $count = (int) $conn->incr($key);
            if ($count === 1) {
                $conn->expire($key, $ttl);
            }
        }
    }

    public function failuresForUser(string $username): int
    {
        return (int) $this->cache->conn()->get($this->cache->key('fail', 'user', $username));
    }

    public function failuresForIp(string $ip): int
    {
        return (int) $this->cache->conn()->get($this->cache->key('fail', 'ip', $ip));
    }

    public function blocked(string $username, ?string $ip): bool
    {
        if ($this->isBlocked($username)) {
            return true;
        }

        if ($this->failuresForUser($username) >= $this->threshold()) {
            return true;
        }

        return $ip !== null && $this->failuresForIp($ip) >= $this->threshold();
    }

    public function clear(string $username, ?string $ip): void
    {
        $keys = array_filter([
            $this->cache->key('fail', 'user', $username),
            $ip ? $this->cache->key('fail', 'ip', $ip) : null,
        ]);

        if ($keys !== []) {
            $this->cache->conn()->del($keys);
        }
    }

    /** 主动封禁（例如配额刚用完），避免每次都回库判配额。 */
    public function block(string $username, string $reason): void
    {
        $this->cache->conn()->setex($this->cache->key('block', $username), $this->blockSeconds(), $reason);
    }

    public function blockReason(string $username): ?string
    {
        $raw = $this->cache->conn()->get($this->cache->key('block', $username));

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function isBlocked(string $username): bool
    {
        return $this->blockReason($username) !== null;
    }

    public function unblock(string $username): void
    {
        $this->cache->conn()->del($this->cache->key('block', $username));
    }
}
