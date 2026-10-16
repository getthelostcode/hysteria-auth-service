<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrafficDaily;
use App\Models\User;
use App\Services\OnlineTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function __invoke(Request $request, OnlineTracker $online): JsonResponse
    {
        abort_unless($request->user() !== null, 403);

        $today = TrafficDaily::query()->where('day', now()->toDateString())->get();

        return response()->json(['data' => [
            'users_total' => User::query()->count(),
            'users_enabled' => User::query()->enabled()->count(),
            'users_disabled' => User::query()->disabled()->count(),
            'users_expired' => User::query()->expired()->count(),
            'users_over_quota' => User::query()->overQuota()->count(),
            'online_now' => $online->onlineCount(),
            'today_tx' => (int) $today->sum('tx'),
            'today_rx' => (int) $today->sum('rx'),
        ]]);
    }
}
