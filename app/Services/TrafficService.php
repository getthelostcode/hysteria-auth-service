<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TrafficDaily;
use App\Models\TrafficReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 流量入账。
 *
 * Hysteria 在连接建立（tx=0）与断开（带该连接的累计字节）时都会打 /auth，
 * 所以同一个 addr 的多次上报必须做**会话差分**，否则会把累计值反复叠加。
 *
 * 热路径只写 Redis（计数器 + 待落库队列），每分钟由 `traffic:flush` 批量落库：
 *   - users.used_bytes / tx_bytes / rx_bytes
 *   - traffic_daily（当日）
 *   - traffic_reports（原始增量账）
 */
class TrafficService
{
    public function __construct(private readonly AuthCache $cache)
    {
    }

    /** 未落库的用量（Redis 计数器之和）——配额判定要用 DB + 它。 */
    public function pending(string $username): int
    {
        $conn = $this->cache->conn();

        return (int) $conn->get($this->cache->key('used', 'tx', $username))
            + (int) $conn->get($this->cache->key('used', 'rx', $username));
    }

    public function pendingTx(string $username): int
    {
        return (int) $this->cache->conn()->get($this->cache->key('used', 'tx', $username));
    }

    public function pendingRx(string $username): int
    {
        return (int) $this->cache->conn()->get($this->cache->key('used', 'rx', $username));
    }

    /**
     * 记一次上报。$tx/$rx 是 Hysteria 给的**连接累计值**（可为 null = 本次没带）。
     *
     * @return array{tx_delta:int,rx_delta:int} 本次真正入账的增量
     */
    public function ingest(string $username, ?string $addr, ?int $tx, ?int $rx, string $source = 'auth'): array
    {
        if ($tx === null && $rx === null) {
            return ['tx_delta' => 0, 'rx_delta' => 0];
        }

        $conn = $this->cache->conn();
        $addrKey = $this->cache->key('sess', $username, $addr ?? '-');
        $prev = $conn->hmget($addrKey, ['tx', 'rx']);
        $prevTx = (int) ($prev[0] ?? 0);
        $prevRx = (int) ($prev[1] ?? 0);

        $curTx = $tx === null ? $prevTx : max(0, $tx);
        $curRx = $rx === null ? $prevRx : max(0, $rx);

        // 累计值变小 = 计数器被重置（新连接/服务重启）→ 直接按新值计，不产生负数
        $dtx = $curTx >= $prevTx ? $curTx - $prevTx : $curTx;
        $drx = $curRx >= $prevRx ? $curRx - $prevRx : $curRx;

        $conn->hmset($addrKey, ['tx' => $curTx, 'rx' => $curRx, 'last' => time()]);
        $conn->expire($addrKey, max(600, (int) config('hyauth.online_ttl', 90) * 8));

        if ($dtx === 0 && $drx === 0) {
            return ['tx_delta' => 0, 'rx_delta' => 0];
        }

        if ($dtx > 0) {
            $conn->incrby($this->cache->key('used', 'tx', $username), $dtx);
        }
        if ($drx > 0) {
            $conn->incrby($this->cache->key('used', 'rx', $username), $drx);
        }

        $conn->sadd($this->cache->key('dirty'), $username);
        $conn->rpush($this->cache->key('reports'), json_encode([
            'username' => $username,
            'addr' => $addr,
            'tx_delta' => $dtx,
            'rx_delta' => $drx,
            'source' => $source,
            'reported_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE));
        $conn->ltrim($this->cache->key('reports'), -20000, -1);

        return ['tx_delta' => $dtx, 'rx_delta' => $drx];
    }

    /** 直接按增量入账（/traffic_report 兼容面用）。 */
    public function ingestDeltas(string $username, int $txDelta, int $rxDelta, ?string $addr = null, string $source = 'api'): void
    {
        $conn = $this->cache->conn();

        if ($txDelta > 0) {
            $conn->incrby($this->cache->key('used', 'tx', $username), $txDelta);
        }
        if ($rxDelta > 0) {
            $conn->incrby($this->cache->key('used', 'rx', $username), $rxDelta);
        }

        $conn->sadd($this->cache->key('dirty'), $username);
        $conn->rpush($this->cache->key('reports'), json_encode([
            'username' => $username,
            'addr' => $addr,
            'tx_delta' => $txDelta,
            'rx_delta' => $rxDelta,
            'source' => $source,
            'reported_at' => now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 把 Redis 里的增量落库（`traffic:flush`，每分钟一次）。返回落库明细供日志/测试断言。
     */
    public function flush(int $reportBatch = 500): array
    {
        $conn = $this->cache->conn();
        $result = ['users' => 0, 'tx' => 0, 'rx' => 0, 'reports' => 0, 'skipped_lock' => false];

        if (! $conn->set($this->cache->key('lock', 'flush'), '1', 'EX', 55, 'NX')) {
            $result['skipped_lock'] = true;

            return $result;
        }

        try {
            $usernames = $conn->smembers($this->cache->key('dirty')) ?: [];
            $day = now()->toDateString();

            foreach ($usernames as $username) {
                $username = (string) $username;
                $tx = (int) $conn->getset($this->cache->key('used', 'tx', $username), 0);
                $rx = (int) $conn->getset($this->cache->key('used', 'rx', $username), 0);

                if ($tx === 0 && $rx === 0) {
                    $conn->srem($this->cache->key('dirty'), $username);

                    continue;
                }

                $user = User::query()->where('username', $username)->first();

                DB::transaction(function () use ($user, $username, $tx, $rx, $day) {
                    if ($user !== null) {
                        User::query()->where('id', $user->id)->update([
                            'used_bytes' => DB::raw('used_bytes + '.($tx + $rx)),
                            'tx_bytes' => DB::raw('tx_bytes + '.$tx),
                            'rx_bytes' => DB::raw('rx_bytes + '.$rx),
                            'updated_at' => now(),
                        ]);

                        $daily = TrafficDaily::query()->firstOrNew(['username' => $username, 'day' => $day]);
                        $daily->user_id = $user->id;
                        $daily->tx = (int) $daily->tx + $tx;
                        $daily->rx = (int) $daily->rx + $rx;
                        $daily->save();
                    }

                    TrafficReport::query()->create([
                        'user_id' => $user?->id,
                        'username' => $username,
                        'addr' => null,
                        'tx_delta' => $tx,
                        'rx_delta' => $rx,
                        'source' => 'flush',
                        'reported_at' => now(),
                    ]);
                });

                $conn->srem($this->cache->key('dirty'), $username);
                $result['users']++;
                $result['tx'] += $tx;
                $result['rx'] += $rx;
            }

            // 明细账（/auth 每次上报都 push 了一条）按批落库
            $raw = $conn->lrange($this->cache->key('reports'), 0, $reportBatch - 1) ?: [];
            foreach ($raw as $line) {
                $row = json_decode((string) $line, true);
                if (! is_array($row)) {
                    $result['reports']++;

                    continue;
                }

                $user = User::query()->where('username', (string) $row['username'])->first();
                TrafficReport::query()->create([
                    'user_id' => $user?->id,
                    'username' => (string) $row['username'],
                    'addr' => $row['addr'] ?? null,
                    'tx_delta' => (int) ($row['tx_delta'] ?? 0),
                    'rx_delta' => (int) ($row['rx_delta'] ?? 0),
                    'source' => (string) ($row['source'] ?? 'auth'),
                    'reported_at' => $row['reported_at'] ?? now()->toDateTimeString(),
                ]);
                $result['reports']++;
            }

            if ($raw !== []) {
                $conn->ltrim($this->cache->key('reports'), count($raw), -1);
            }
        } finally {
            $conn->del($this->cache->key('lock', 'flush'));
        }

        return $result;
    }
}
