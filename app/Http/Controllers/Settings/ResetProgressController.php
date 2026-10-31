<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ResetLearningProgress;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ResetProgressController extends Controller
{
    public function __invoke(Request $request, ResetLearningProgress $resetLearningProgress): Response
    {
        $resetLearningProgress->handle($request->user());

        return response()->noContent();
    }
}
