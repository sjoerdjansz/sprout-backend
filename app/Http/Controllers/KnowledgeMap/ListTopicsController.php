<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\ListKnowledgeMap;
use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeMapTopicResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListTopicsController extends Controller
{
    public function __invoke(Request $request, ListKnowledgeMap $listKnowledgeMap): AnonymousResourceCollection
    {
        return KnowledgeMapTopicResource::collection($listKnowledgeMap->handle($request->user()));
    }
}
