<?php

namespace App\Http\Controllers\Practice;

use App\Actions\FindCurrentPracticeSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\PracticeSessionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShowCurrentPracticeSessionController extends Controller
{
    public function __invoke(Request $request, FindCurrentPracticeSession $findCurrentPracticeSession): PracticeSessionResource|Response
    {
        $result = $findCurrentPracticeSession->handle($request->user());

        // Nothing to resume is a normal answer, not an error: the frontend goes to the setup.
        if ($result === null) {
            return response()->noContent();
        }

        return new PracticeSessionResource($result);
    }
}
