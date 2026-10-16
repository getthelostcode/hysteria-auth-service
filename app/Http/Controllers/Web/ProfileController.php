<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OnlineTracker;
use App\Services\SettingStore;
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
        ]);
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
