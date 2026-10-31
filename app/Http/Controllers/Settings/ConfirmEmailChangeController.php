<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ConfirmEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ConfirmEmailChangeRequest;
use App\Http\Resources\UserResource;

class ConfirmEmailChangeController extends Controller
{
    public function __invoke(ConfirmEmailChangeRequest $request, ConfirmEmailChange $confirmEmailChange): UserResource
    {
        return new UserResource($confirmEmailChange->handle($request->validated('token')));
    }
}
