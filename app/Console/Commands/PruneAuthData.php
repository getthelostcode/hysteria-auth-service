<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\TrafficReport;
use App\Models\UserSession;
use Illuminate\Console\Command;

class PruneAuthData extends Command
{
    protected $signature = 'hyauth:prune
        {--days=30 : traffic_reports 保留天数}
        {--audit-days=90 : 鉴权拒答类审计保留天数}
        {--sessions-days=7 : 已结束会话保留天数}';

    protected $description = '清理过期数据（明细账、拒答审计、已结束会话）';

    public function handle(): int
    {
        $reports = TrafficReport::query()
            ->where('reported_at', '<', now()->subDays((int) $this->option('days')))
            ->delete();

        $audits = AuditLog::query()
            ->where('action', 'like', 'auth.%')
            ->where('created_at', '<', now()->subDays((int) $this->option('audit-days')))
            ->delete();

        $sessions = UserSession::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', now()->subDays((int) $this->option('sessions-days')))
            ->delete();

        $this->info("清理完成：明细账 {$reports} 条、拒答审计 {$audits} 条、历史会话 {$sessions} 条。");

        return self::SUCCESS;
    }
}
