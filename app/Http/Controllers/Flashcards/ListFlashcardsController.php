<?php

namespace App\Http\Controllers\Flashcards;

use App\Actions\ListSubtopicFlashcards;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlashcardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListFlashcardsController extends Controller
{
    public function __invoke(Request $request, int $subtopic, ListSubtopicFlashcards $listSubtopicFlashcards): AnonymousResourceCollection
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $subtopic = $request->user()->subtopics()->findOrFail($subtopic);

        return FlashcardResource::collection($listSubtopicFlashcards->handle($subtopic, $request->user()));
    }
}
