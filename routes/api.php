<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\RequestMagicLinkController;
use App\Http\Controllers\Auth\VerifyMagicLinkController;
use App\Http\Controllers\Conversations\ListConversationsController;
use App\Http\Controllers\Conversations\SaveConversationController;
use App\Http\Controllers\Conversations\SendMessageController;
use App\Http\Controllers\Conversations\ShowConversationController;
use App\Http\Controllers\Conversations\StartConversationController;
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

Route::middleware('auth:sanctum')->prefix('conversations')->name('conversations.')->group(function () {
    Route::get('/', ListConversationsController::class)->name('index');
    Route::post('/', StartConversationController::class)->middleware('throttle:tutor-messages')->name('store');
    Route::get('/{conversation}', ShowConversationController::class)->whereNumber('conversation')->name('show');
    Route::post('/{conversation}/messages', SendMessageController::class)
        ->whereNumber('conversation')
        ->middleware('throttle:tutor-messages')
        ->name('messages.store');
    Route::post('/{conversation}/save', SaveConversationController::class)
        ->whereNumber('conversation')
        ->middleware('throttle:save-conversations')
        ->name('save');
});
