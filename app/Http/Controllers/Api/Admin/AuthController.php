<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var AdminUser|null $admin */
        $admin = AdminUser::query()->where('email', $data['email'])->first();

        if ($admin === null || ! Hash::check($data['password'], (string) $admin->password)) {
            throw ValidationException::withMessages(['email' => '邮箱或密码不正确。']);
        }

        if (! $admin->enabled) {
            throw ValidationException::withMessages(['email' => '该管理员账号已被禁用。']);
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $token = $admin->createToken($data['device_name'] ?? 'api')->plainTextToken;

        $audit->log('admin.login', $admin, ['device' => $data['device_name'] ?? 'api'], $request->ip());

        return response()->json([
            'token' => $token,
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return response()->json(['data' => [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'role_label' => $admin->roleLabel(),
        ]]);
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $admin->currentAccessToken()?->delete();
        $audit->log('admin.logout', $admin, [], $request->ip());

        return response()->json(['status' => 'ok']);
    }
}
