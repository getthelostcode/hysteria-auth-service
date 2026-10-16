<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * POST /auth 的全部判定逻辑。
 *
 * 契约（官方文档）：请求 {addr, auth, tx[, rx]}；响应必须 200 + {"ok":bool,"id":string}。
 * 判定顺序：格式 → 限流/封禁 → 用户存在 → 密码 → 启用 → 未过期 → 配额 → 设备数。
 *
 * 热路径要求：命中 Redis 快照就不查库；写库动作（last_online_at、会话行）都有 15 秒门控。
 */
class HysteriaAuthService
{
    public function __construct(
        private readonly UserStateCache $states,
        private readonly OnlineTracker $online,
        private readonly TrafficService $traffic,
        private readonly FailureTracker $fails,
        private readonly AuditLogger $audit,
        private readonly AuthCache $cache,
    ) {
    }

    public function authenticate(string $addr, string $auth, ?int $tx = null, ?int $rx = null): AuthResult
    {
        $ip = self::ipOf($addr);

        // 1) 格式：必须是 username:password（密码里允许再有冒号）
        $parts = explode(':', $auth, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || $parts[1] === '') {
            $this->audit->denial('malformed', null, $ip, ['addr' => $addr]);

            return AuthResult::deny('malformed');
        }

        $username = trim($parts[0]);
        $password = $parts[1];

        // 2) 限流 / 短期封禁
        $blockReason = $this->fails->blockReason($username);
        if ($blockReason !== null) {
            return AuthResult::deny($blockReason === 'quota' ? 'quota_exceeded' : 'rate_limited');
        }
        if ($this->fails->blocked($username, $ip)) {
            $this->audit->denial('rate_limited', $username, $ip, ['addr' => $addr]);

            return AuthResult::deny('rate_limited');
        }

        // 3) 用户快照（Redis 未命中才查库）
        $state = $this->states->resolve($username);
        if ($state === null) {
            $this->fails->record($username, $ip);
            $this->audit->denial('unknown_user', $username, $ip, ['addr' => $addr]);

            return AuthResult::deny('unknown_user');
        }

        // 4) 密码
        if (! $this->states->verifyPassword($username, $password, (string) $state['password_hash'])) {
            $this->fails->record($username, $ip);
            $this->audit->denial('bad_password', $username, $ip, ['addr' => $addr]);

            return AuthResult::deny('bad_password');
        }

        // 5) 启用
        if (! $state['enabled']) {
            $this->audit->denial('disabled', $username, $ip, ['addr' => $addr]);

            return AuthResult::deny('disabled');
        }

        // 6) 到期
        if ($state['expires_at'] !== null && (int) $state['expires_at'] < time()) {
            $this->audit->denial('expired', $username, $ip, ['addr' => $addr]);

            return AuthResult::deny('expired');
        }

        // 7) 配额：DB 里的已用量 + 还没落库的 Redis 计数
        $used = (int) $state['used_bytes'] + $this->traffic->pending($username);
        if ((int) $state['quota_bytes'] > 0 && $used >= (int) $state['quota_bytes']) {
            $this->fails->block($username, 'quota');
            $this->audit->denial('quota_exceeded', $username, $ip, [
                'addr' => $addr, 'used' => $used, 'quota' => (int) $state['quota_bytes'],
            ]);

            return AuthResult::deny('quota_exceeded');
        }

        // 8) 在线设备数（按 IP 去重；同一 IP 重连不算新设备）
        $devices = $this->online->devices($username);
        $maxDevices = (int) $state['max_devices'];
        $isNewDevice = $ip === null || ! array_key_exists($ip, $devices);
        if ($maxDevices > 0 && $isNewDevice && count($devices) >= $maxDevices) {
            $this->audit->denial('too_many_devices', $username, $ip, [
                'addr' => $addr, 'devices' => array_keys($devices), 'max' => $maxDevices,
            ]);

            return AuthResult::deny('too_many_devices');
        }

        // 通过：记在线、记流量（会话差分）、清失败计数
        $this->online->touch($username, $addr, $ip ?? '-');
        $this->traffic->ingest($username, $addr, $tx, $rx);
        $this->fails->clear($username, $ip);
        $this->touchUser($username, $ip);

        return AuthResult::allow($username, ['devices' => $this->online->deviceCount($username)]);
    }

    public static function ipOf(string $addr): ?string
    {
        $addr = trim($addr);
        if ($addr === '') {
            return null;
        }

        // IPv6: [2001:db8::1]:443
        if (str_starts_with($addr, '[')) {
            $end = strpos($addr, ']');

            return $end === false ? $addr : substr($addr, 1, $end - 1);
        }

        $pos = strrpos($addr, ':');

        return $pos === false ? $addr : substr($addr, 0, $pos);
    }

    /** last_online_at / last_ip 按用户 15 秒门控写库（5000 用户规模下不能每请求一写）。 */
    private function touchUser(string $username, ?string $ip): void
    {
        $gate = $this->cache->key('seen', $username);

        if (! $this->cache->conn()->set($gate, '1', 'EX', 15, 'NX')) {
            return;
        }

        User::query()->where('username', $username)->update([
            'last_online_at' => now(),
            'last_ip' => $ip,
            'updated_at' => now(),
        ]);
    }
}
