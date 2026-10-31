<?php

namespace App\Http\Controllers\Flashcards;

use App\Actions\ListDueFlashcards;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlashcardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListDueFlashcardsController extends Controller
{
    public function __invoke(Request $request, ListDueFlashcards $listDueFlashcards): AnonymousResourceCollection
    {
        return FlashcardResource::collection($listDueFlashcards->handle($request->user()));
    }
}
