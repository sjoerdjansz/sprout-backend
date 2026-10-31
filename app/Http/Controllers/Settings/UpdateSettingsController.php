<?php

namespace App\Http\Controllers\Settings;

use App\Actions\UpdateSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Resources\SettingsResource;

class UpdateSettingsController extends Controller
{
    public function __invoke(UpdateSettingsRequest $request, UpdateSettings $updateSettings): SettingsResource
    {
        return new SettingsResource($updateSettings->handle($request->user()->settings, $request->validated()));
    }
}
