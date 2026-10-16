<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hysteria;

use App\Http\Controllers\Controller;
use App\Services\HysteriaAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /auth —— Hysteria2 的 HTTP 鉴权入口。
 *
 * 契约：**永远返回 HTTP 200**，用 {"ok":bool,"id":string} 表达结果；
 * 非 200 会被 Hysteria 当成鉴权失败（官方文档明确要求 200）。
 * 所以这里把一切异常都兜住，绝不让 500 泄漏给节点。
 */
class AuthController extends Controller
{
    public function __invoke(Request $request, HysteriaAuthService $auth): JsonResponse
    {
        $addr = (string) $request->input('addr', '');
        $rawAuth = (string) $request->input('auth', '');

        // tx / rx：Hysteria 给的是该连接的累计字节；有些版本只带 tx
        $tx = $request->has('tx') ? (int) $request->input('tx') : null;
        $rx = $request->has('rx') ? (int) $request->input('rx') : null;

        try {
            $result = $auth->authenticate($addr, $rawAuth, $tx, $rx);
        } catch (Throwable $e) {
            report($e);

            // 内部错误也必须给 Hysteria 一个可解析的拒绝（宁可拒一次连接，也不要 500 造成雪崩）
            return response()->json(['ok' => false, 'id' => ''])->setStatusCode(200);
        }

        return response()->json($result->toArray())->setStatusCode(200);
    }
}
