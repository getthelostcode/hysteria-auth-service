<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * POST /auth 的全部判定逻辑。
 *
 * 请求：{addr, auth, tx[, rx]}；响应必须 200 + {"ok":bool,"id":string}（官方契约）。
 *
 * `auth` 有两种形态（由设置项 auth_mode 决定允许哪种）：
 *   - UUID：`"550e8400-e29b-41d4-a716-446655440000"`（默认推荐，可重置）
 *   - 用户名密码：`"alice:secret"`
 * 无论哪种，返回的 `id` 都是**用户名**（Hysteria 的流量统计按它归类）。
 *
 * 判定顺序：格式/形态 → 限流封禁 → 用户存在 → 密码（仅用户名形态）→ 启用 → 未过期 → 配额 → 设备数。
 * 热路径：命中 Redis 快照不查库；写库动作都有 15 秒门控。
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
        private readonly SettingStore $settings,
    ) {
    }

    public function authenticate(string $addr, string $auth, ?int $tx = null, ?int $rx = null): AuthResult
    {
        $ip = self::ipOf($addr);
        $raw = trim($auth);
        $mode = $this->settings->string('auth_mode');

        if ($raw === '') {
            $this->audit->denial('malformed', null, $ip, ['addr' => $addr]);

            return AuthResult::deny('malformed');
        }

        // 形态识别：uuid（无冒号）还是 用户名:密码
        $isUuid = ! str_contains($raw, ':') && User::isUuid($raw);
        $identity = null;      // 限流与封禁用的身份（uuid 形态就是 uuid 本身）
        $password = null;
        $state = null;

        if ($isUuid) {
            $raw = strtolower($raw);   // uuid 统一小写：SQLite 的字符比较是大小写敏感的
            if ($mode === 'password') {
                $this->audit->denial('uuid_disabled', null, $ip, ['addr' => $addr]);

                return AuthResult::deny('uuid_disabled');
            }
            $identity = strtolower($raw);
        } else {
            $parts = explode(':', $raw, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '' || $parts[1] === '') {
                $this->audit->denial('malformed', null, $ip, ['addr' => $addr]);

                return AuthResult::deny('malformed');
            }
            if ($mode === 'uuid') {
                $this->audit->denial('password_disabled', trim($parts[0]), $ip, ['addr' => $addr]);

                return AuthResult::deny('password_disabled');
            }
            $identity = trim($parts[0]);
            $password = $parts[1];
        }

        // 限流 / 短期封禁
        $blockReason = $this->fails->blockReason($identity);
        if ($blockReason !== null) {
            return AuthResult::deny($blockReason === 'quota' ? 'quota_exceeded' : 'rate_limited');
        }
        if ($this->fails->blocked($identity, $ip)) {
            $this->audit->denial('rate_limited', $identity, $ip, ['addr' => $addr]);

            return AuthResult::deny('rate_limited');
        }

        // 用户快照（Redis 未命中才查库）
        $state = $isUuid
            ? $this->states->resolveByUuid($raw)
            : $this->states->resolve($identity);

        if ($state === null) {
            $this->fails->record($identity, $ip);
            $this->audit->denial('unknown_user', $isUuid ? $raw : $identity, $ip, ['addr' => $addr, 'form' => $isUuid ? 'uuid' : 'password']);

            return AuthResult::deny('unknown_user');
        }

        $username = (string) $state['username'];

        // 密码（仅 用户名:密码 形态；uuid 形态的凭据就是 uuid 本身）
        if (! $isUuid && ! $this->states->verifyPassword($username, (string) $password, (string) $state['password_hash'])) {
            $this->fails->record($identity, $ip);
            $this->audit->denial('bad_password', $username, $ip, ['addr' => $addr]);

            return AuthResult::deny('bad_password');
        }

        // 启用
        if (! $state['enabled']) {
            $this->audit->denial('disabled', $username, $ip, ['addr' => $addr]);

            return AuthResult::deny('disabled');
        }

        // 到期
        if ($state['expires_at'] !== null && (int) $state['expires_at'] < time()) {
            $this->audit->denial('expired', $username, $ip, ['addr' => $addr]);

            return AuthResult::deny('expired');
        }

        // 配额：已落库 + 未落库的 Redis 计数
        $used = (int) $state['used_bytes'] + $this->traffic->pending($username);
        if ((int) $state['quota_bytes'] > 0 && $used >= (int) $state['quota_bytes']) {
            $this->fails->block($identity, 'quota');
            $this->audit->denial('quota_exceeded', $username, $ip, [
                'addr' => $addr, 'used' => $used, 'quota' => (int) $state['quota_bytes'],
            ]);

            return AuthResult::deny('quota_exceeded');
        }

        // 在线设备数（按 IP 去重；同一 IP 重连不算新设备）
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
        $this->fails->clear($identity, $ip);
        $this->touchUser($username, $ip);

        return AuthResult::allow($username, [
            'devices' => $this->online->deviceCount($username),
            'form' => $isUuid ? 'uuid' : 'password',
        ]);
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

    /** last_online_at / last_ip 按用户 15 秒门控写库。 */
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
