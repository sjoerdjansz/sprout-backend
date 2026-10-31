<?php

namespace App\Http\Controllers\Settings;

use App\Actions\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Http\Resources\UserResource;

class UpdateProfileController extends Controller
{
    public function __invoke(UpdateProfileRequest $request, UpdateProfile $updateProfile): UserResource
    {
        return new UserResource($updateProfile->handle($request->user(), $request->validated('name')));
    }
}
