<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    private int $devices = 0;

    private ?int $pending = null;

    private ?array $deviceIps = null;

    public function withDevices(int $devices): self
    {
        $this->devices = $devices;

        return $this;
    }

    public function withPending(?int $pending): self
    {
        $this->pending = $pending;

        return $this;
    }

    public function withDeviceIps(array $ips): self
    {
        $this->deviceIps = $ips;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'username' => $user->username,
            'uuid' => $user->uuid,
            'uuid_rotated_at' => $user->uuid_rotated_at?->toIso8601String(),
            'email' => $user->email,
            'enabled' => $user->enabled,
            'status' => $user->statusLabel(),
            'expires_at' => $user->expires_at?->toIso8601String(),
            'expired' => $user->isExpired(),
            'quota_bytes' => $user->quota_bytes,
            'used_bytes' => $user->used_bytes,
            'pending_bytes' => $this->pending,
            'quota_percent' => $user->quotaPercent(),
            'tx_bytes' => $user->tx_bytes,
            'rx_bytes' => $user->rx_bytes,
            'max_devices' => $user->max_devices,
            'devices' => $this->devices,
            'device_ips' => $this->deviceIps,
            'online' => $user->isOnline(),
            'last_online_at' => $user->last_online_at?->toIso8601String(),
            'last_ip' => $user->last_ip,
            'note' => $user->note,
            'disabled_at' => $user->disabled_at?->toIso8601String(),
            'disabled_reason' => $user->disabled_reason,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
