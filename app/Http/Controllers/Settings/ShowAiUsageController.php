<?php

namespace App\Http\Controllers\Settings;

use App\Actions\SummariseAiUsage;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiUsageResource;
use Illuminate\Http\Request;

class ShowAiUsageController extends Controller
{
    public function __invoke(Request $request, SummariseAiUsage $summariseAiUsage): AiUsageResource
    {
        return new AiUsageResource($summariseAiUsage->handle($request->user()));
    }
}
