<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user() !== null, 403);

        $query = AuditLog::query()->orderByDesc('id');

        if (($action = $request->query('action')) && is_string($action)) {
            $query->where('action', 'like', $action.'%');
        }

        if (($username = $request->query('username')) && is_string($username)) {
            $query->where('target_id', $username);
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
