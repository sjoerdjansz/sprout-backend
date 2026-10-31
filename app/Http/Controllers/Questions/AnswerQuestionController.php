<?php

namespace App\Http\Controllers\Questions;

use App\Actions\AnswerQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Questions\AnswerQuestionRequest;
use App\Http\Resources\AnsweredQuestionResource;
use Illuminate\Http\JsonResponse;

class AnswerQuestionController extends Controller
{
    public function __invoke(AnswerQuestionRequest $request, int $question, AnswerQuestion $answerQuestion): JsonResponse
    {
        // Looked up among the learner's own questions, so someone else's is a 404.
        $question = $request->user()->questions()->findOrFail($question);

        $result = $answerQuestion->handle(
            question: $question,
            learner: $request->user(),
            optionId: $request->validated('option_id'),
            response: $request->validated('response'),
            isSecondTry: $request->boolean('second_try'),
            doesNotKnow: $request->boolean('dont_know'),
            practiceSession: $request->practiceSession(),
        );

        return (new AnsweredQuestionResource($result))->response()->setStatusCode(201);
    }
}
