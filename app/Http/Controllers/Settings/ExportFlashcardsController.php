<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ExportFlashcardsCsv;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExportFlashcardsController extends Controller
{
    public function __invoke(Request $request, ExportFlashcardsCsv $exportFlashcardsCsv): Response
    {
        $fileName = 'sprout-flashcards-'.now($request->user()->timezone())->toDateString().'.csv';

        return response($exportFlashcardsCsv->handle($request->user()), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
