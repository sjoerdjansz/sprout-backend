<?php

namespace App\Http\Controllers\Insights;

use App\Actions\SummariseWeeklyInsights;
use App\Http\Controllers\Controller;
use App\Http\Resources\WeeklyInsightsResource;
use Illuminate\Http\Request;

class ShowWeeklyInsightsController extends Controller
{
    public function __invoke(Request $request, SummariseWeeklyInsights $summariseWeeklyInsights): WeeklyInsightsResource
    {
        return new WeeklyInsightsResource($summariseWeeklyInsights->handle($request->user()));
    }
}
