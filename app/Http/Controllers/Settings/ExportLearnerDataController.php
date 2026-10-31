<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ExportLearnerData;
use App\Http\Controllers\Controller;
use App\Http\Resources\LearnerExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExportLearnerDataController extends Controller
{
    public function __invoke(Request $request, ExportLearnerData $exportLearnerData): JsonResponse
    {
        $fileName = 'sprout-export-'.now($request->user()->timezone())->toDateString().'.json';

        return (new LearnerExportResource($exportLearnerData->handle($request->user())))
            ->response($request)
            ->header('Content-Disposition', 'attachment; filename="'.$fileName.'"');
    }
}
