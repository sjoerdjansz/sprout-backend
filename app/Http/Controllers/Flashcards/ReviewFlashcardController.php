<?php

namespace App\Http\Controllers\Flashcards;

use App\Actions\ReviewFlashcard;
use App\Enums\ReviewRating;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\ReviewFlashcardRequest;
use App\Http\Resources\ReviewedFlashcardResource;
use Illuminate\Http\JsonResponse;

class ReviewFlashcardController extends Controller
{
    public function __invoke(ReviewFlashcardRequest $request, int $flashcard, ReviewFlashcard $reviewFlashcard): JsonResponse
    {
        // Looked up among the learner's own cards, so someone else's is a 404.
        $flashcard = $request->user()->flashcards()->findOrFail($flashcard);

        $result = $reviewFlashcard->handle(
            flashcard: $flashcard,
            learner: $request->user(),
            rating: ReviewRating::from($request->validated('rating')),
            practiceSession: $request->practiceSession(),
        );

        return (new ReviewedFlashcardResource($result))->response()->setStatusCode(201);
    }
}
