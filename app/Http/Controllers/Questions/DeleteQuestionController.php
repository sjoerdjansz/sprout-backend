<?php

namespace App\Http\Controllers\Questions;

use App\Actions\DeleteQuestion;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteQuestionController extends Controller
{
    public function __invoke(Request $request, int $question, DeleteQuestion $deleteQuestion): Response
    {
        // Looked up among the learner's own questions, so someone else's is a 404.
        $deleteQuestion->handle($request->user()->questions()->findOrFail($question));

        return response()->noContent();
    }
}
