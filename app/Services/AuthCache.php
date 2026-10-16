<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * 鉴权服务的 Redis 入口：统一前缀、统一连接、提供按前缀清理。
 * 用它而不是 Cache 门面，是因为在线集合/计数器/限流都要原生命令。
 */
class AuthCache
{
    public function conn(): Connection
    {
        return Redis::connection((string) config('hyauth.redis_connection', 'auth'));
    }

    /** hyauth:xxx:yyy */
    public function key(string ...$parts): string
    {
        return rtrim((string) config('hyauth.cache_prefix', 'hyauth'), ':').':'.implode(':', $parts);
    }

    public function prefix(): string
    {
        return rtrim((string) config('hyauth.cache_prefix', 'hyauth'), ':').':';
    }

    /** 用户状态变了（禁用/改配额/改密码/删除）→ 把该用户的判定缓存清干净。 */
    public function forgetUser(string $username): void
    {
        $conn = $this->conn();
        $fingerprints = $conn->keys($this->key('pwd', $username).':*') ?: [];
        $keys = array_merge([$this->key('user', $username), $this->key('block', $username)], $fingerprints);
        if ($keys !== []) {
            $conn->del($keys);
        }
    }

    /**
     * 清掉本前缀下的所有键（面板「清空缓存」按钮 / 测试用）。
     *
     * 用 KEYS + 分批 DEL，而不是 SCAN：这里只在本前缀下、且只由管理动作或测试调用，
     * 键的数量级是「每个用户 1-3 个」；SCAN 的游标在 Laravel 的连接包装器上
     * 无法按引用传递（phpredis 的 scan 需要 &$cursor），写错就是静默死循环。
     * 绝不用 FLUSHDB —— 同库可能还有别的应用。
     */
    public function flushPrefix(): int
    {
        $conn = $this->conn();
        $keys = $conn->keys($this->prefix().'*') ?: [];
        $deleted = 0;

        foreach (array_chunk($keys, 500) as $chunk) {
            $deleted += (int) $conn->del($chunk);
        }

        return $deleted;
    }
}
