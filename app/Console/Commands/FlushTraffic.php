<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TrafficService;
use Illuminate\Console\Command;

class FlushTraffic extends Command
{
    protected $signature = 'traffic:flush {--batch=500 : 单次落库的明细账条数}';

    protected $description = '把 Redis 里的流量增量落库（users.used_bytes / traffic_daily / traffic_reports）';

    public function handle(TrafficService $traffic): int
    {
        $result = $traffic->flush((int) $this->option('batch'));

        if ($result['skipped_lock']) {
            $this->warn('已有 flush 在跑，跳过。');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'flush 完成：用户 %d 个（tx %s / rx %s），明细账 %d 条。',
            $result['users'],
            $this->human($result['tx']),
            $this->human($result['rx']),
            $result['reports'],
        ));

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, 2).$units[$i];
    }
}
