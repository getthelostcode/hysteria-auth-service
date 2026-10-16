<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| 调度（生产用 crontab 每分钟 `php artisan schedule:run`，或 systemd timer）：
*/

// 秒级热路径只写 Redis，这里每分钟把增量落库
Schedule::command('traffic:flush')->everyMinute()->withoutOverlapping();

// 清理明细账/拒答审计/历史会话
Schedule::command('hyauth:prune')->dailyAt('03:30')->withoutOverlapping();
