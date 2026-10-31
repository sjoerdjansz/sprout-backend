<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\SettingsResource;
use Illuminate\Http\Request;

class ShowSettingsController extends Controller
{
    public function __invoke(Request $request): SettingsResource
    {
        return new SettingsResource($request->user()->settings);
    }
}
