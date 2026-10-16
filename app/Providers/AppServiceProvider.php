<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AdminUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerGates();
        $this->registerRateLimiters();
    }

    /** 面板与 API 共用的一套能力判定（角色 → 能力）。 */
    private function registerGates(): void
    {
        Gate::define('manage-users', fn (AdminUser $user) => $user->canManageUsers());
        Gate::define('manage-settings', fn (AdminUser $user) => $user->isSuperAdmin());
        Gate::define('manage-admins', fn (AdminUser $user) => $user->isSuperAdmin());
        Gate::define('view-data', fn (AdminUser $user) => true);
    }

    private function registerRateLimiters(): void
    {
        // Hysteria 的 /auth 调用方是节点上的 hysteria-server（通常就一两个 IP），
        // 且会随客户端连接/断开成批到达 —— 这里只做防雪崩的粗保护，
        // 真正的防爆破交给 FailureTracker（按用户名/IP 的失败计数与短期封禁）。
        RateLimiter::for('hysteria-auth', fn (Request $request) => Limit::perMinute(
            (int) env('AUTH_RATE_LIMIT_PER_MIN', 3000)
        )->by($request->ip()));

        RateLimiter::for('hysteria-report', fn (Request $request) => Limit::perMinute(600)->by((string) $request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by((string) $request->ip()));

        RateLimiter::for('admin-api', fn (Request $request) => Limit::perMinute(600)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
