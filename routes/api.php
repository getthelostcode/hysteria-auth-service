<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AuditController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\InviteController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Hysteria\AuthController;
use App\Http\Controllers\Hysteria\HealthController;
use App\Http\Controllers\Hysteria\KickController;
use App\Http\Controllers\Hysteria\TrafficReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hysteria 面（调用方是节点上的 hysteria-server，永远 200）
|--------------------------------------------------------------------------
*/

Route::post('/auth', AuthController::class)->middleware('throttle:hysteria-auth')->name('hysteria.auth');
Route::get('/health', HealthController::class)->name('hysteria.health');
Route::post('/traffic_report', TrafficReportController::class)->middleware('throttle:hysteria-report')->name('hysteria.traffic');
Route::post('/internal/kick', KickController::class)->name('hysteria.kick');

/*
|--------------------------------------------------------------------------
| 管理 API（Sanctum Bearer，供脚本/自动化/面板外调用）
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-api');

    Route::middleware(['auth:sanctum', 'throttle:admin-api'])->group(function () {
        Route::post('auth/logout', [AdminAuthController::class, 'logout']);
        Route::get('auth/me', [AdminAuthController::class, 'me']);

        Route::get('stats', StatsController::class);
        Route::get('audit', AuditController::class);

        Route::get('users', [AdminUserController::class, 'index']);
        Route::post('users', [AdminUserController::class, 'store']);
        Route::get('users/{username}', [AdminUserController::class, 'show']);
        Route::patch('users/{username}', [AdminUserController::class, 'update']);
        Route::post('users/{username}/enable', [AdminUserController::class, 'enable']);
        Route::post('users/{username}/disable', [AdminUserController::class, 'disable']);
        Route::post('users/{username}/password', [AdminUserController::class, 'password']);
        Route::post('users/{username}/uuid/reset', [AdminUserController::class, 'resetUuid']);
        Route::post('users/{username}/quota/reset', [AdminUserController::class, 'resetUsage']);
        Route::post('users/{username}/kick', [AdminUserController::class, 'kick']);
        Route::delete('users/{username}', [AdminUserController::class, 'destroy']);
        Route::get('users/{username}/traffic', [AdminUserController::class, 'traffic']);

        Route::get('invites', [InviteController::class, 'index']);
        Route::post('invites', [InviteController::class, 'store']);
        Route::delete('invites/{id}', [InviteController::class, 'destroy']);
    });
});
