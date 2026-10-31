<?php

namespace App\Http\Controllers\Practice;

use App\Actions\FinishPracticeSession;
use App\Actions\SummarisePracticeSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\PracticeSessionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FinishPracticeSessionController extends Controller
{
    public function __invoke(
        Request $request,
        int $practiceSession,
        FinishPracticeSession $finishPracticeSession,
        SummarisePracticeSession $summarisePracticeSession,
    ): PracticeSessionResource|Response {
        // Looked up among the learner's own practice sessions, so someone else's is a 404.
        $session = $request->user()->practiceSessions()->findOrFail($practiceSession);

        $finished = $finishPracticeSession->handle($session, $request->user());

        // Nothing was practised, so the session is gone.
        if ($finished === null) {
            return response()->noContent();
        }

        return new PracticeSessionResource($summarisePracticeSession->handle($finished, $request->user()));
    }
}
