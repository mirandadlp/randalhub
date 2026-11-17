<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Session (cookie) authentication for the SPA. CSRF cookie: GET /sanctum/csrf-cookie.
Route::prefix('auth')->group(function () {
    Route::get('status', [AuthController::class, 'status']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth');
});

// Single-page app: every non-API route renders the Vue shell.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|auth|up).*$')->name('spa');
