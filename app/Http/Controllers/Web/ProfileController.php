<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthCache;
use App\Services\OnlineTracker;
use App\Services\SettingStore;
use App\Services\UserManager;
use App\Services\UserStateCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** 用户自助面：/me */
class ProfileController extends Controller
{
    public function show(Request $request, OnlineTracker $online, SettingStore $settings): View
    {
        /** @var User $user */
        $user = $request->user();
        $devices = $online->devices($user->username);

        return view('me', [
            'brand' => $settings->string('brand_name'),
            'user' => $user,
            'days' => $user->dailyTraffic()->orderByDesc('day')->limit(14)->get(),
            'devices' => $devices,
            'maxDevices' => $user->max_devices,
            'selfUuidResetEnabled' => $settings->bool('user_uuid_reset_enabled'),
            'uuidResetPerHour' => max(1, (int) config('hyauth.self_uuid_reset.per_hour', 5)),
        ]);
    }

    /**
     * 用户自助重置自己的 UUID（换一把钥匙）。
     *
     * 必须**重新输入当前密码**：UUID 就是凭据，如果只凭会话就能换，
     * 会话被偷走的人就能把真正的用户锁在门外。
     * 另外按用户限流（默认 5 次/小时），防止反复换钥匙把客户端搞乱。
     */
    public function resetUuid(
        Request $request,
        UserManager $manager,
        SettingStore $settings,
        AuthCache $cache,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        abort_unless($settings->bool('user_uuid_reset_enabled'), 403, '管理员已关闭自助重置 UUID。');

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'disconnect' => ['nullable', 'boolean'],
        ], [], ['current_password' => '当前密码']);

        if (! Hash::check($data['current_password'], (string) $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => '当前密码不正确。']);
        }

        // 限流：同一用户每小时最多重置 N 次
        $limit = max(1, (int) config('hyauth.self_uuid_reset.per_hour', 5));
        $key = $cache->key('uuidrot', $user->username);
        $count = (int) $cache->conn()->incr($key);
        if ($count === 1) {
            $cache->conn()->expire($key, 3600);
        }
        if ($count > $limit) {
            throw ValidationException::withMessages([
                'current_password' => "重置过于频繁（每小时最多 {$limit} 次），请稍后再试。",
            ]);
        }

        $new = $manager->resetUuid(
            $user,
            null,
            $request->boolean('disconnect'),
            'user.uuid_self_reset',
        );

        return back()->with('status', 'UUID 已重置，旧的那串立刻失效。')
            ->with('uuid_rotated', $new);
    }

    public function updatePassword(Request $request, UserStateCache $states): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $min = max(6, app(SettingStore::class)->int('min_password_length'));

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.$min, 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], (string) $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => '当前密码不正确。']);
        }

        $user->password_hash = Hash::make($data['password']);
        $user->save();

        // 让新旧密码的判定缓存立刻失效
        $states->forget($user->username);

        return back()->with('status', '密码已更新，新密码立即生效。');
    }
}
