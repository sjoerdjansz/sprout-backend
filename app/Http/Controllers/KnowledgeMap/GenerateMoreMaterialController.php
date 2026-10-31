<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\GenerateMoreMaterial;
use App\Http\Controllers\Controller;
use App\Http\Resources\SavedSubtopicResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerateMoreMaterialController extends Controller
{
    public function __invoke(Request $request, int $subtopic, GenerateMoreMaterial $generateMoreMaterial): JsonResponse
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $subtopic = $request->user()->subtopics()->findOrFail($subtopic);

        // 202: the material is made in the background.
        return (new SavedSubtopicResource($generateMoreMaterial->handle($subtopic, $request->user())))->response()->setStatusCode(202);
    }
}
