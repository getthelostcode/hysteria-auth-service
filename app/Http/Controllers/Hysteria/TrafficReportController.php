<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hysteria;

use App\Http\Controllers\Controller;
use App\Services\SettingStore;
use App\Services\TrafficService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /traffic_report —— 兼容旧 FastAPI 版的按增量上报（给外部采集器/脚本用）。
 * 与 /auth 不同，这个面带共享密钥，鉴权失败会返回非 200。
 */
class TrafficReportController extends Controller
{
    public function __invoke(Request $request, TrafficService $traffic, SettingStore $settings): JsonResponse
    {
        if (! $this->authorized($request, $settings)) {
            return response()->json(['status' => 'error', 'message' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:64'],
            'tx_delta' => ['nullable', 'integer', 'min:0'],
            'rx_delta' => ['nullable', 'integer', 'min:0'],
        ]);

        $traffic->ingestDeltas(
            $data['user_id'],
            (int) ($data['tx_delta'] ?? 0),
            (int) ($data['rx_delta'] ?? 0),
            null,
            'api',
        );

        return response()->json(['status' => 'ok']);
    }

    private function authorized(Request $request, SettingStore $settings): bool
    {
        $token = (string) config('hyauth.traffic_report_token', '');
        if ($token === '' || ! $settings->bool('traffic_report_enabled')) {
            return false; // 未配置即视为关闭
        }

        $given = (string) ($request->header('X-Auth-Token') ?? $request->bearerToken() ?? '');

        return $given !== '' && hash_equals($token, $given);
    }
}
