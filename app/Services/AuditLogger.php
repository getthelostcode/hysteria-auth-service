<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** 管理动作与鉴权拒答的审计（只增不删）。 */
class AuditLogger
{
    public function __construct(
        private readonly AuthCache $cache,
        private readonly SettingStore $settings,
    ) {
    }

    public function log(string $action, ?Model $target = null, array $meta = [], ?string $ip = null): AuditLog
    {
        [$actorType, $actorId, $actorLabel] = $this->actor();

        return AuditLog::query()->create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel,
            'action' => $action,
            'target_type' => $target !== null ? class_basename($target) : '',
            'target_id' => $target !== null ? (string) ($target->getKey() ?? '') : '',
            'target_label' => $target instanceof User ? $target->username : ($target !== null ? (string) ($target->name ?? '') : ''),
            'meta' => $meta,
            'ip' => $ip ?? request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * 鉴权拒答。量大，所以：① 受设置项开关控制；② 同一 (原因, 用户/IP) 10 分钟只记一条。
     */
    public function denial(string $reason, ?string $username, ?string $ip, array $meta = []): void
    {
        if (! $this->settings->bool('denial_audit_enabled')) {
            return;
        }

        $identity = $username !== null && $username !== '' ? $username : (string) $ip;
        $dedupeKey = $this->cache->key('deny', $reason, substr(hash('sha256', $identity), 0, 16));

        if (! $this->cache->conn()->set($dedupeKey, '1', 'EX', 600, 'NX')) {
            return; // 10 分钟内同一个身份的同一种拒绝只留一条
        }

        AuditLog::query()->create([
            'actor_type' => 'hysteria',
            'actor_id' => null,
            'actor_label' => (string) ($username ?? ''),
            'action' => 'auth.'.$reason,
            'target_type' => 'User',
            'target_id' => (string) ($username ?? ''),
            'target_label' => (string) ($username ?? ''),
            'meta' => $meta,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }

    /**
     * 审计里的操作者。面板走 admin guard，管理 API 走 sanctum（token 的持有者是 AdminUser），
     * 自助面走 web guard；都没有就是 system（命令行/定时任务）。
     *
     * @return array{0:string,1:?int,2:string}
     */
    private function actor(): array
    {
        foreach (['admin', 'sanctum', 'web'] as $guard) {
            try {
                $who = Auth::guard($guard)->user();
            } catch (\Throwable) {
                continue;   // 该 guard 未配置时跳过
            }

            if ($who instanceof AdminUser) {
                return ['admin', (int) $who->id, (string) $who->email];
            }

            if ($who instanceof User) {
                return ['user', (int) $who->id, (string) $who->username];
            }
        }

        return ['system', null, 'system'];
    }
}
