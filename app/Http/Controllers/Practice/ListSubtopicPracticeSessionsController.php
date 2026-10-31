<?php

namespace App\Http\Controllers\Practice;

use App\Actions\ListSubtopicPracticeSessions;
use App\Http\Controllers\Controller;
use App\Http\Resources\PracticeSessionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListSubtopicPracticeSessionsController extends Controller
{
    public function __invoke(Request $request, int $subtopic, ListSubtopicPracticeSessions $listSubtopicPracticeSessions): AnonymousResourceCollection
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $subtopic = $request->user()->subtopics()->findOrFail($subtopic);

        return PracticeSessionResource::collection($listSubtopicPracticeSessions->handle($subtopic, $request->user()));
    }
}
