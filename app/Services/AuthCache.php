<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * 鉴权服务的 Redis 入口：统一前缀、统一连接、按前缀清理。
 * 用原生命令（在线集合/计数器/限流），不走 Cache 门面。
 *
 * 注意：连接上的 phpredis OPT_PREFIX 必须为空（config/database.php 的 auth 连接里已置空），
 * 否则会变成「<app>_database_hyauth:*」，DEL/EXISTS 与文档里的键名都对不上。
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

    /**
     * 用户状态变了（禁用/改配额/改密码/重置 uuid/删除）→ 把判定缓存清干净。
     * 快照同时挂在 username 与 uuid 两个键上，两个都要删。
     */
    public function forgetUser(string $username, ?string $uuid = null): void
    {
        $conn = $this->conn();
        $fingerprints = $conn->keys($this->key('pwd', $username).':*') ?: [];
        $keys = array_merge(
            [$this->key('user', $username), $this->key('block', $username)],
            $fingerprints,
        );

        if ($uuid !== null && $uuid !== '') {
            $keys[] = $this->key('uuid', $uuid);
        }

        if ($keys !== []) {
            $conn->del($keys);
        }
    }

    /**
     * 清掉本前缀下的所有键（面板「清空缓存」/ 测试用）。
     * 用 KEYS + 分批 DEL：phpredis 的 SCAN 游标按引用传，Laravel 的连接包装器按值转发，
     * 手写 SCAN 循环会静默死循环。这里只由管理动作/测试调用，键数量级可控。
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
