<?php

use App\Http\Controllers\Hysteria\AuthController;
use App\Http\Controllers\Hysteria\HealthController;
use App\Http\Controllers\Hysteria\KickController;
use App\Http\Controllers\Hysteria\TrafficReportController;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // 根路径别名：旧 FastAPI 版就是这几个路径，Hysteria 节点已经这么配了，
            // 不迁移配置就能直接换后端（nginx 上游从 uvicorn 改成 php-fpm 即可）。
            // 用 api 中间件组，避开 web 的 session/CSRF —— Hysteria 只会 POST JSON。
            Route::middleware('api')->group(function () {
                Route::post('/auth', AuthController::class)->middleware('throttle:hysteria-auth')->name('hysteria.auth.root');
                Route::get('/health', HealthController::class)->name('hysteria.health.root');
                Route::post('/traffic_report', TrafficReportController::class)->middleware('throttle:hysteria-report')->name('hysteria.traffic.root');
                Route::post('/internal/kick', KickController::class)->name('hysteria.kick.root');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // auth.ipx.sarl 走 Cloudflare/nginx 回源：不配可信代理，审计里的客户端 IP 全是 127.0.0.1
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1,::1'));

        // Hysteria 面必须永远是 JSON（它不做 HTML 跳转）
        $middleware->api(prepend: [ForceJsonResponse::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
