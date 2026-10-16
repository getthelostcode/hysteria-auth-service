<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 从旧 FastAPI 版（MySQL `hysteria` 库）导入用户。
 *
 * 旧库的 password_hash 就是 bcrypt，Laravel 的 Hash::check 直接认，
 * 所以导入时**原样搬运**，用户不需要重置密码。
 */
class ImportLegacyUsers extends Command
{
    protected $signature = 'users:import-legacy
        {--dsn= : 旧库 DSN，形如 mysql://user:pass@127.0.0.1:3306/hysteria}
        {--dry-run : 只打印将要导入的内容}';

    protected $description = '导入旧 FastAPI 服务的用户与流量记录';

    public function handle(): int
    {
        $dsn = (string) ($this->option('dsn') ?: env('LEGACY_DSN', ''));
        if ($dsn === '') {
            $this->error('缺少 --dsn（或环境变量 LEGACY_DSN）。');

            return self::FAILURE;
        }

        $parts = parse_url($dsn);
        if ($parts === false || ! isset($parts['host'], $parts['path'])) {
            $this->error('DSN 解析失败，示例：mysql://user:pass@127.0.0.1:3306/hysteria');

            return self::FAILURE;
        }

        config(['database.connections.legacy' => [
            'driver' => 'mysql',
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 3306,
            'database' => ltrim($parts['path'], '/'),
            'username' => $parts['user'] ?? 'root',
            'password' => $parts['pass'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]]);

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $updated = 0;

        DB::connection('legacy')->table('users')->orderBy('id')->chunk(500, function ($rows) use (&$created, &$updated, $dryRun) {
            foreach ($rows as $row) {
                $username = (string) $row->id;
                $existing = User::withTrashed()->where('username', $username)->first();

                $this->line(sprintf(
                    '%s 用户 %s (enabled=%s)',
                    $dryRun ? '[dry-run]' : '[import]',
                    $username,
                    (int) $row->enabled === 1 ? 'yes' : 'no',
                ));

                if ($dryRun) {
                    continue;
                }

                if ($existing !== null) {
                    $existing->restore();
                    $existing->password_hash = (string) $row->password_hash;
                    $existing->enabled = (int) $row->enabled === 1;
                    $existing->save();
                    $updated++;

                    continue;
                }

                User::query()->create([
                    'username' => $username,
                    'password_hash' => (string) $row->password_hash,
                    'enabled' => (int) $row->enabled === 1,
                    'note' => '由旧 FastAPI 版导入',
                ]);
                $created++;
            }
        });

        if (! $dryRun && $this->legacyHasTrafficTable()) {
            $this->info('汇总旧 traffic 表…');
            $aggregates = DB::connection('legacy')->table('traffic')
                ->selectRaw('user_id, SUM(tx_delta) as tx, SUM(rx_delta) as rx')
                ->groupBy('user_id')
                ->get();

            foreach ($aggregates as $row) {
                User::query()->where('username', (string) $row->user_id)->update([
                    'tx_bytes' => (int) $row->tx,
                    'rx_bytes' => (int) $row->rx,
                    'used_bytes' => (int) $row->tx + (int) $row->rx,
                ]);
            }
            $this->info("流量汇总完成：{$aggregates->count()} 个用户。");
        }

        $this->info("导入完成：新建 {$created}，更新 {$updated}。");

        return self::SUCCESS;
    }

    private function legacyHasTrafficTable(): bool
    {
        try {
            return DB::connection('legacy')->getSchemaBuilder()->hasTable('traffic');
        } catch (\Throwable) {
            return false;
        }
    }
}
