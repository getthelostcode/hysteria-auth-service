<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Services\OnlineTracker;
use App\Services\RegistrationService;
use App\Services\TrafficService;
use App\Services\UserManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request, OnlineTracker $online): JsonResponse
    {
        $this->assertCan($request, 'view');

        $query = User::query();

        if (($status = $request->query('status')) && is_string($status)) {
            match ($status) {
                'enabled' => $query->enabled(),
                'disabled' => $query->disabled(),
                'expired' => $query->expired(),
                'over_quota' => $query->overQuota(),
                'online' => $query->online(),
                default => null,
            };
        }

        if (($search = $request->query('search')) && is_string($search) && $search !== '') {
            $query->where(fn ($q) => $q->where('username', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $page = $query->orderByDesc('id')->paginate($perPage);

        $data = [];
        foreach ($page->items() as $user) {
            $data[] = (new UserResource($user))
                ->withDevices($online->deviceCount($user->username))
                ->toArray($request);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, UserManager $manager, RegistrationService $registration): JsonResponse
    {
        $this->assertCan($request, 'manage');

        $data = $request->validate([
            'username' => ['required', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:6'],
            'email' => ['nullable', 'email', 'max:120'],
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
            'max_devices' => ['nullable', 'integer', 'min:0'],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $registration->assertUsernameAvailable($data['username']);
        $data['password'] ??= UserManager::randomPassword();

        $user = $manager->create($data);

        return response()->json([
            'data' => (new UserResource($user))->toArray($request),
            'password' => $data['password'],
        ], 201);
    }

    public function show(Request $request, string $username, OnlineTracker $online, TrafficService $traffic): JsonResponse
    {
        $this->assertCan($request, 'view');
        $user = $this->find($username);

        return response()->json(['data' => (new UserResource($user))
            ->withDevices($online->deviceCount($user->username))
            ->withPending($traffic->pending($user->username))
            ->withDeviceIps(array_keys($online->devices($user->username)))
            ->toArray($request)]);
    }

    public function update(Request $request, string $username, UserManager $manager): JsonResponse
    {
        $this->assertCan($request, 'manage');

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:120'],
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
            'max_devices' => ['nullable', 'integer', 'min:0'],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $user = $manager->updateProfile($this->find($username), $data);

        return response()->json(['data' => (new UserResource($user))->toArray($request)]);
    }

    public function enable(Request $request, string $username, UserManager $manager): JsonResponse
    {
        $this->assertCan($request, 'manage');
        $user = $manager->enable($this->find($username), (string) $request->input('reason', ''));

        return response()->json(['data' => (new UserResource($user))->toArray($request)]);
    }

    public function disable(Request $request, string $username, UserManager $manager): JsonResponse
    {
        $this->assertCan($request, 'manage');
        $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $user = $manager->disable($this->find($username), (string) $request->input('reason', ''));

        return response()->json(['data' => (new UserResource($user))->toArray($request)]);
    }

    public function password(Request $request, string $username, UserManager $manager): JsonResponse
    {
        $this->assertCan($request, 'manage');
        $request->validate(['password' => ['nullable', 'string', 'min:6']]);

        $plain = $manager->resetPassword($user = $this->find($username), $request->input('password'));

        return response()->json([
            'data' => (new UserResource($user))->toArray($request),
            'password' => $plain,
        ]);
    }

    public function resetUsage(Request $request, string $username, UserManager $manager): JsonResponse
    {
        $this->assertCan($request, 'manage');
        $user = $manager->resetUsage($this->find($username));

        return response()->json(['data' => (new UserResource($user))->toArray($request)]);
    }

    public function kick(Request $request, string $username, UserManager $manager): JsonResponse
    {
        $this->assertCan($request, 'manage');
        $request->validate(['addr' => ['nullable', 'string', 'max:64'], 'reason' => ['nullable', 'string', 'max:120']]);

        $manager->kick($this->find($username), $request->input('addr'), (string) $request->input('reason', 'admin'));

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, string $username, UserManager $manager): JsonResponse
    {
        $this->assertCan($request, 'manage');
        $manager->delete($this->find($username));

        return response()->json(['status' => 'ok']);
    }

    public function traffic(Request $request, string $username): JsonResponse
    {
        $this->assertCan($request, 'view');
        $days = min(180, max(1, (int) $request->query('days', 30)));
        $user = $this->find($username);

        $rows = $user->dailyTraffic()->where('day', '>=', now()->subDays($days)->toDateString())
            ->orderBy('day')->get();

        return response()->json(['data' => $rows->map(fn ($row) => [
            'day' => $row->day->toDateString(),
            'tx' => $row->tx,
            'rx' => $row->rx,
            'total' => $row->tx + $row->rx,
        ])->all()]);
    }

    private function find(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }

    private function assertCan(Request $request, string $ability): void
    {
        $admin = $request->user();

        if ($ability === 'manage') {
            abort_unless($admin?->canManageUsers(), 403, '需要运维或超级管理员权限。');

            return;
        }

        abort_unless($admin !== null, 403);
    }
}
