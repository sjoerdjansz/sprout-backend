<?php

namespace App\Http\Controllers\Auth;

use App\Actions\LogInWithMagicLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyMagicLinkRequest;
use App\Http\Resources\AccessTokenResource;

class VerifyMagicLinkController extends Controller
{
    public function __invoke(VerifyMagicLinkRequest $request, LogInWithMagicLink $logInWithMagicLink): AccessTokenResource
    {
        $newAccessToken = $logInWithMagicLink->handle($request->validated('token'));

        return new AccessTokenResource($newAccessToken);
    }
}
