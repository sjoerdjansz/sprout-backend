<?php

namespace App\Http\Controllers\Practice;

use App\Actions\StartPracticeSession;
use App\Enums\PracticeMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Practice\StartPracticeSessionRequest;
use App\Http\Resources\PracticeSessionResource;
use App\Models\Subtopic;
use Illuminate\Http\JsonResponse;

class StartPracticeSessionController extends Controller
{
    public function __invoke(StartPracticeSessionRequest $request, StartPracticeSession $startPracticeSession): JsonResponse
    {
        $learner = $request->user();
        $subtopicId = $request->validated('subtopic_id');
        $explainSubtopicId = $request->validated('explain_subtopic_id');

        $result = $startPracticeSession->handle(
            learner: $learner,
            // No topic: a review of everything due today, across topics.
            topic: $request->isDueReview() ? null : $learner->topics()->findOrFail($request->validated('topic_id')),
            subtopic: $subtopicId === null ? null : Subtopic::query()->findOrFail($subtopicId),
            modes: array_map(fn (string $mode): PracticeMode => PracticeMode::from($mode), $request->validated('modes')),
            cardIds: $request->validated('card_ids'),
            questionIds: $request->validated('question_ids'),
            explainSubtopic: $explainSubtopicId === null ? null : Subtopic::query()->findOrFail($explainSubtopicId),
        );

        return (new PracticeSessionResource($result))->response()->setStatusCode(201);
    }
}
