<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUser $registerUser): JsonResponse
    {
        $registerUser->handle(
            name: $request->validated('name'),
            email: $request->validated('email'),
            interests: $request->interests(),
            wantsDailyReminder: $request->boolean('wants_daily_reminder'),
        );

        return response()->json(['message' => 'Check your inbox for your magic link.'], 202);
    }
}
