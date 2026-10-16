<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OnlineTracker;
use App\Services\SettingStore;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(OnlineTracker $online, SettingStore $settings): View
    {
        return view('home', [
            'brand' => $settings->string('brand_name'),
            'registrationEnabled' => $settings->bool('registration_enabled'),
            'onlineUsers' => $online->onlineCount(),
            'totalUsers' => User::query()->count(),
        ]);
    }
}
