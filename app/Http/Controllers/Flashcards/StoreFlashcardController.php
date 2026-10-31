<?php

namespace App\Http\Controllers\Flashcards;

use App\Actions\AddFlashcard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\FlashcardRequest;
use App\Http\Resources\FlashcardResource;
use Illuminate\Http\JsonResponse;

class StoreFlashcardController extends Controller
{
    public function __invoke(FlashcardRequest $request, int $subtopic, AddFlashcard $addFlashcard): JsonResponse
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $subtopic = $request->user()->subtopics()->findOrFail($subtopic);

        $result = $addFlashcard->handle(
            subtopic: $subtopic,
            learner: $request->user(),
            front: $request->validated('front'),
            back: $request->validated('back'),
        );

        return (new FlashcardResource($result))->response()->setStatusCode(201);
    }
}
