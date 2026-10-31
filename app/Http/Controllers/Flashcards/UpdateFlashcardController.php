<?php

namespace App\Http\Controllers\Flashcards;

use App\Actions\EditFlashcard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashcards\FlashcardRequest;
use App\Http\Resources\FlashcardResource;

class UpdateFlashcardController extends Controller
{
    public function __invoke(FlashcardRequest $request, int $flashcard, EditFlashcard $editFlashcard): FlashcardResource
    {
        // Looked up among the learner's own cards, so someone else's is a 404.
        $flashcard = $request->user()->flashcards()->findOrFail($flashcard);

        return new FlashcardResource($editFlashcard->handle(
            flashcard: $flashcard,
            learner: $request->user(),
            front: $request->validated('front'),
            back: $request->validated('back'),
        ));
    }
}
