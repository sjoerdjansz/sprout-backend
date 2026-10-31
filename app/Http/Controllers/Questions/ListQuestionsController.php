<?php

namespace App\Http\Controllers\Questions;

use App\Actions\ListSubtopicQuestions;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListQuestionsController extends Controller
{
    public function __invoke(Request $request, int $subtopic, ListSubtopicQuestions $listSubtopicQuestions): AnonymousResourceCollection
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $subtopic = $request->user()->subtopics()->findOrFail($subtopic);

        return QuestionResource::collection($listSubtopicQuestions->handle($subtopic, $request->user()));
    }
}
