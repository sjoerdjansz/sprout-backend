<?php

namespace App\Http\Controllers\Flashcards;

use App\Actions\DeleteFlashcard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteFlashcardController extends Controller
{
    public function __invoke(Request $request, int $flashcard, DeleteFlashcard $deleteFlashcard): Response
    {
        // Looked up among the learner's own cards, so someone else's is a 404.
        $deleteFlashcard->handle($request->user()->flashcards()->findOrFail($flashcard));

        return response()->noContent();
    }
}
