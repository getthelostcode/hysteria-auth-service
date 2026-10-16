<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 在线状态：谁在线、几台设备（按 IP 去重）、待踢队列。
 * 全部走 Redis，热路径不写库；窗口之外的成员在读取时顺手剔除。
 */
class OnlineTracker
{
    public function __construct(private readonly AuthCache $cache)
    {
    }

    public function ttl(): int
    {
        return max(15, (int) config('hyauth.online_ttl', 90));
    }

    /** 记一次在线（每次成功鉴权都刷），返回该用户当前活跃设备数。 */
    public function touch(string $username, string $addr, string $ip): int
    {
        $conn = $this->cache->conn();
        $now = time();

        $conn->zadd($this->cache->key('dev', $username), [$ip => $now]);
        $conn->expire($this->cache->key('dev', $username), $this->ttl() * 4);

        $conn->zadd($this->cache->key('online'), [$username => $now]);
        $conn->expire($this->cache->key('online'), $this->ttl() * 4);

        $this->pruneUser($username);

        // 连接维度：给面板看「这台设备是什么时候连的」，地址级
        $conn->zadd($this->cache->key('addr', $username), [$addr => $now]);
        $conn->expire($this->cache->key('addr', $username), $this->ttl() * 4);

        return $this->deviceCount($username);
    }

    /** ip => 最后活跃时间戳 */
    public function devices(string $username): array
    {
        $this->pruneUser($username);
        $rows = $this->cache->conn()->zrangebyscore(
            $this->cache->key('dev', $username),
            (string) (time() - $this->ttl()),
            '+inf',
            ['withscores' => true],
        ) ?: [];

        // phpredis 的 withscores 返回 [member => score]
        $out = [];
        foreach ($rows as $member => $score) {
            $out[(string) $member] = (int) $score;
        }

        return $out;
    }

    /** addr => 最后活跃时间戳 */
    public function addresses(string $username): array
    {
        $rows = $this->cache->conn()->zrangebyscore(
            $this->cache->key('addr', $username),
            (string) (time() - $this->ttl()),
            '+inf',
            ['withscores' => true],
        ) ?: [];

        $out = [];
        foreach ($rows as $member => $score) {
            $out[(string) $member] = (int) $score;
        }

        return $out;
    }

    public function deviceCount(string $username): int
    {
        return count($this->devices($username));
    }

    /** 全网在线用户数（顺手清理过期成员）。 */
    public function onlineCount(): int
    {
        $conn = $this->cache->conn();
        $conn->zremrangebyscore($this->cache->key('online'), '-inf', (string) (time() - $this->ttl()));

        return (int) $conn->zcard($this->cache->key('online'));
    }

    /** 禁用/删除用户：立刻从在线集合里抹掉。 */
    public function forget(string $username): void
    {
        $conn = $this->cache->conn();
        $conn->del([$this->cache->key('dev', $username), $this->cache->key('addr', $username)]);
        $conn->zrem($this->cache->key('online'), $username);
    }

    public function pushKick(string $username, ?string $addr = null, string $reason = ''): void
    {
        $this->cache->conn()->rpush($this->cache->key('kick', $username), json_encode([
            'addr' => $addr,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));
        $this->cache->conn()->expire($this->cache->key('kick', $username), 3600);
    }

    public function pendingKicks(string $username): array
    {
        $items = $this->cache->conn()->lrange($this->cache->key('kick', $username), 0, -1) ?: [];

        return array_values(array_filter(array_map(
            fn ($raw) => is_string($raw) ? json_decode($raw, true) : null,
            $items,
        )));
    }

    private function pruneUser(string $username): void
    {
        $min = (string) (time() - $this->ttl());
        $this->cache->conn()->zremrangebyscore($this->cache->key('dev', $username), '-inf', $min);
        $this->cache->conn()->zremrangebyscore($this->cache->key('addr', $username), '-inf', $min);
    }
}
