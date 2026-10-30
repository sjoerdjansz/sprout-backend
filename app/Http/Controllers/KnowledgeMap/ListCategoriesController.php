<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCategoriesController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        // Oldest first, so the starter categories come before the ones the learner made.
        return CategoryResource::collection($request->user()->categories()->orderBy('id')->get());
    }
}
