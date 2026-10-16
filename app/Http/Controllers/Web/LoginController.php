<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SettingStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(SettingStore $settings): View
    {
        return view('auth.login', ['brand' => $settings->string('brand_name')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('username', $data['username'])->first();

        if ($user === null || ! Auth::guard('web')->attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['username' => '用户名或密码不正确。']);
        }

        if (! $user->enabled) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages(['username' => '该账号已被禁用，请与管理员联系。']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('me'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
