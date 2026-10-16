<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Services\RegistrationService;
use App\Services\SettingStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function show(Request $request, SettingStore $settings): View
    {
        abort_unless($settings->bool('registration_enabled'), 404);

        return view('auth.register', [
            'brand' => $settings->string('brand_name'),
            'requiresInvite' => $settings->bool('registration_requires_invite'),
            'minPassword' => max(6, $settings->int('min_password_length')),
            'defaultQuota' => $settings->int('default_quota_bytes'),
            'defaultDays' => $settings->int('default_valid_days'),
            'invitePreview' => $this->invitePreview((string) $request->query('invite', '')),
        ]);
    }

    public function store(Request $request, RegistrationService $registration): RedirectResponse
    {
        $minPassword = max(6, app(SettingStore::class)->int('min_password_length'));

        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'invite_code' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:'.$minPassword, 'confirmed'],
        ], [], [
            'username' => '用户名', 'password' => '密码', 'invite_code' => '邀请码', 'email' => '邮箱',
        ]);

        try {
            ['user' => $user] = $registration->register($data, $request->ip());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        Auth::guard('web')->login($user);

        return redirect()->route('me')->with('status', '注册成功，账号已生效。');
    }

    private function invitePreview(string $code): ?array
    {
        if ($code === '') {
            return null;
        }

        $invite = InviteCode::query()->where('code', $code)->first();

        if ($invite === null || ! $invite->isUsable()) {
            return ['valid' => false, 'code' => $code];
        }

        return [
            'valid' => true,
            'code' => $invite->code,
            'quota_bytes' => $invite->quota_bytes,
            'valid_days' => $invite->valid_days,
        ];
    }
}
