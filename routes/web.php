<?php

declare(strict_types=1);

use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\RegisterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 用户自助面（Hysteria 用户；与后台管理员分开）
|--------------------------------------------------------------------------
*/

Route::get('/', HomeController::class)->name('home');

Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:register');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth:web')->group(function () {
    Route::get('/me', [ProfileController::class, 'show'])->name('me');
    Route::patch('/me/password', [ProfileController::class, 'updatePassword'])->name('me.password');
    Route::post('/me/uuid/reset', [ProfileController::class, 'resetUuid'])->name('me.uuid.reset');
});
