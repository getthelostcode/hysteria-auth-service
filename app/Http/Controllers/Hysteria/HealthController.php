<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hysteria;

use App\Http\Controllers\Controller;
use App\Services\AuthCache;
use App\Services\OnlineTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** GET /health —— 兼容旧版的健康检查，附带几个能一眼看出问题的数字。 */
class HealthController extends Controller
{
    public function __invoke(OnlineTracker $online, AuthCache $cache): JsonResponse
    {
        $db = 'ok';
        $redis = 'ok';

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $db = 'error';
        }

        try {
            $cache->conn()->ping();
        } catch (\Throwable $e) {
            $redis = 'error';
        }

        return response()->json([
            'status' => $db === 'ok' && $redis === 'ok' ? 'ok' : 'degraded',
            'database' => $db,
            'redis' => $redis,
            'online_users' => $redis === 'ok' ? $online->onlineCount() : null,
        ]);
    }
}
