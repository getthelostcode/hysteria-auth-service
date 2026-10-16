<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 后台管理员（Filament 面板）。与 Hysteria 用户（users 表）完全分离：
 * 自助注册的账号永不可能拿到面板权限。
 */
class AdminUser extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens;
    use HasFactory;

    public const ROLE_SUPER_ADMIN = 'super-admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN => '超级管理员',
        self::ROLE_OPERATOR => '运维',
        self::ROLE_VIEWER => '只读',
    ];

    protected $fillable = ['name', 'email', 'password', 'role', 'enabled', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'enabled' => 'bool',
            'last_login_at' => 'datetime',
        ];
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->enabled && array_key_exists((string) $this->role, self::ROLES);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isViewer(): bool
    {
        return $this->role === self::ROLE_VIEWER;
    }

    /** 能改用户（启用/禁用/配额/密码/删除）。 */
    public function canManageUsers(): bool
    {
        return in_array($this->role, [self::ROLE_SUPER_ADMIN, self::ROLE_OPERATOR], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? (string) $this->role;
    }
}
