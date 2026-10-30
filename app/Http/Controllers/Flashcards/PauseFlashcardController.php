<?php

namespace App\Http\Controllers\Flashcards;

use App\Actions\SetFlashcardPaused;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlashcardResource;
use Illuminate\Http\Request;

class PauseFlashcardController extends Controller
{
    public function __invoke(Request $request, int $flashcard, SetFlashcardPaused $setFlashcardPaused): FlashcardResource
    {
        // Looked up among the learner's own cards, so someone else's is a 404.
        $flashcard = $request->user()->flashcards()->findOrFail($flashcard);

        return new FlashcardResource($setFlashcardPaused->handle($flashcard, $request->user(), paused: true));
    }
}
