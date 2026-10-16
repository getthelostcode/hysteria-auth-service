<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user() !== null, 403);

        return response()->json(['data' => InviteCode::query()->orderByDesc('id')->limit(200)->get()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->canManageUsers(), 403);

        $data = $request->validate([
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $invite = InviteCode::generate(
            (int) ($data['max_uses'] ?? 1),
            $data['quota_bytes'] ?? null,
            $data['valid_days'] ?? null,
            (string) ($data['note'] ?? ''),
            (int) $request->user()->id,
        );

        if (! empty($data['expires_at'])) {
            $invite->forceFill(['expires_at' => $data['expires_at']])->save();
        }

        $audit->log('invite.created', $invite, ['code' => $invite->code, 'max_uses' => $invite->max_uses]);

        return response()->json(['data' => $invite->fresh()], 201);
    }

    public function destroy(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->canManageUsers(), 403);

        $invite = InviteCode::query()->findOrFail($id);
        $invite->delete();
        $audit->log('invite.deleted', null, ['code' => $invite->code]);

        return response()->json(['status' => 'ok']);
    }
}
