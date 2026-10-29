<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\RequestMagicLinkController;
use App\Http\Controllers\Auth\VerifyMagicLinkController;
use App\Http\Controllers\CurrentUserController;
use Illuminate\Support\Facades\Route;

Route::get('/status', function () {
    return ['status' => 'ok'];
})->name('status');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:magic-link')->group(function () {
        Route::post('/register', RegisterController::class)->name('register');
        Route::post('/login', RequestMagicLinkController::class)->name('login');
    });

    Route::post('/verify', VerifyMagicLinkController::class)
        ->middleware('throttle:verify-magic-link')
        ->name('verify');

    Route::post('/logout', LogoutController::class)
        ->middleware('auth:sanctum')
        ->name('logout');
});

Route::get('/user', CurrentUserController::class)
    ->middleware('auth:sanctum')
    ->name('user');
