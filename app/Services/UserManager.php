<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 管理员对 Hysteria 用户的操作。
 *
 * **所有改动状态的方法都必须走 afterStateChange()**：清 Redis 快照（用户名键 + uuid 键）、
 * 清短期封禁，必要时清在线集合 —— 这就是「禁用 / 重置 uuid 秒级生效」的实现位置。
 */
class UserManager
{
    public function __construct(
        private readonly UserStateCache $states,
        private readonly OnlineTracker $online,
        private readonly FailureTracker $fails,
        private readonly AuditLogger $audit,
        private readonly TrafficService $traffic,
    ) {
    }

    public function create(array $data): User
    {
        $user = User::query()->create([
            'username' => $data['username'],
            'password_hash' => Hash::make($data['password']),
            'email' => $data['email'] ?? null,
            'enabled' => $data['enabled'] ?? true,
            'expires_at' => $data['expires_at'] ?? null,
            'quota_bytes' => $data['quota_bytes'] ?? 0,
            'max_devices' => $data['max_devices'] ?? 0,
            'note' => $data['note'] ?? '',
        ]);

        $this->afterStateChange($user);
        $this->audit->log('user.created', $user, [
            'quota_bytes' => $user->quota_bytes,
            'expires_at' => $user->expires_at?->toIso8601String(),
            'max_devices' => $user->max_devices,
            'uuid' => $user->uuid,
        ]);

        return $user;
    }

    public function updateProfile(User $user, array $data): User
    {
        $user->fill(array_intersect_key($data, array_flip(['email', 'note', 'quota_bytes', 'expires_at', 'max_devices'])));
        $user->save();

        $this->afterStateChange($user);
        $this->audit->log('user.updated', $user, $data);

        return $user;
    }

    public function enable(User $user, ?string $reason = null): User
    {
        $user->enabled = true;
        $user->disabled_at = null;
        $user->disabled_reason = '';
        $user->save();

        $this->afterStateChange($user);
        $this->audit->log('user.enabled', $user, ['reason' => $reason]);

        return $user;
    }

    public function disable(User $user, ?string $reason = null): User
    {
        $user->enabled = false;
        $user->disabled_at = now();
        $user->disabled_reason = (string) ($reason ?? '');
        $user->save();

        $this->afterStateChange($user);
        $this->online->forget($user->username);
        $this->online->pushKick($user->username, null, 'disabled');

        $this->audit->log('user.disabled', $user, ['reason' => $reason]);

        return $user;
    }

    public function updateQuota(User $user, ?int $quotaBytes = null, mixed $expiresAt = null, ?int $maxDevices = null): User
    {
        if ($quotaBytes !== null) {
            $user->quota_bytes = max(0, $quotaBytes);
        }
        if ($expiresAt !== null) {
            $user->expires_at = $expiresAt;
        }
        if ($maxDevices !== null) {
            $user->max_devices = max(0, $maxDevices);
        }
        $user->save();

        $this->afterStateChange($user);
        $this->audit->log('user.quota_updated', $user, [
            'quota_bytes' => $user->quota_bytes,
            'expires_at' => $user->expires_at?->toIso8601String(),
            'max_devices' => $user->max_devices,
        ]);

        return $user;
    }

    public function resetUsage(User $user): User
    {
        $user->used_bytes = 0;
        $user->tx_bytes = 0;
        $user->rx_bytes = 0;
        $user->save();

        $this->fails->unblock($user->username);
        $this->afterStateChange($user);
        $this->audit->log('user.usage_reset', $user);

        return $user;
    }

    public function resetPassword(User $user, ?string $plain = null): string
    {
        $plain ??= self::randomPassword();
        $user->password_hash = Hash::make($plain);
        $user->save();

        $this->afterStateChange($user);
        $this->audit->log('user.password_reset', $user);

        return $plain;
    }

    /**
     * 重置 uuid：换一把新钥匙，**旧 uuid 立刻失效**（两个快照键 + 两个封禁键都清）。
     * 传入 $uuid 可指定（用于从别处迁移过来），否则随机生成。
     */
    public function resetUuid(User $user, ?string $uuid = null, bool $kickOnline = false, string $action = 'user.uuid_reset'): string
    {
        $old = (string) $user->uuid;
        $new = $uuid !== null && User::isUuid($uuid) ? strtolower($uuid) : (string) Str::uuid();

        $user->uuid = $new;
        $user->uuid_rotated_at = now();
        $user->save();

        // 旧 uuid 的快照键必须删掉，否则旧钥匙还能用到 TTL 过期
        $this->states->forget($user->username, $old);
        $this->states->forget($user->username, $new);
        $this->fails->unblock($old);
        $this->fails->unblock($new);
        $this->fails->unblock($user->username);

        if ($kickOnline) {
            // 用户自己换钥匙时通常想要「把旧设备都踢掉」
            $this->online->forget($user->username);
            $this->online->pushKick($user->username, null, 'uuid_reset');
        }

        $this->audit->log($action, $user, ['old' => $old, 'new' => $new, 'kick_online' => $kickOnline]);

        return $new;
    }

    public function kick(User $user, ?string $addr = null, string $reason = 'admin'): void
    {
        $this->online->pushKick($user->username, $addr, $reason);
        $this->audit->log('user.kicked', $user, ['addr' => $addr, 'reason' => $reason]);
    }

    public function delete(User $user): void
    {
        $username = $user->username;
        $uuid = (string) $user->uuid;
        $user->delete(); // 软删：流量与审计保留

        $this->states->forget($username, $uuid);
        $this->online->forget($username);
        $this->audit->log('user.deleted', null, ['username' => $username]);
    }

    public static function randomPassword(int $length = 16): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** 状态变更后的统一收尾：清快照（用户名 + uuid）+ 清封禁 + 清「已见过」门控。 */
    private function afterStateChange(User $user): void
    {
        $this->states->forget($user->username, (string) $user->uuid);
        $this->fails->unblock($user->username);
    }
}
