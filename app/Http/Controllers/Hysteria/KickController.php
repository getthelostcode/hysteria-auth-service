<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hysteria;

use App\Http\Controllers\Controller;
use App\Services\OnlineTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /internal/kick —— 管理/自动化标记「该用户需要被踢下线」。
 * 真正的踢人动作由节点侧（hysteria 本地 API）或控制面/agent 执行：
 * 本服务不直连对端的 127.0.0.1:8080。
 */
class KickController extends Controller
{
    public function __invoke(Request $request, OnlineTracker $online): JsonResponse
    {
        $token = (string) config('hyauth.traffic_report_token', '');
        $given = (string) ($request->header('X-Auth-Token') ?? $request->bearerToken() ?? '');

        if ($token === '' || $given === '' || ! hash_equals($token, $given)) {
            return response()->json(['status' => 'error', 'message' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'addr' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $online->pushKick($data['username'], $data['addr'] ?? null, (string) ($data['reason'] ?? 'api'));

        return response()->json(['status' => 'ok']);
    }
}
