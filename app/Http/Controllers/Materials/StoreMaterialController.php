<?php

namespace App\Http\Controllers\Materials;

use App\Actions\StoreMaterial;
use App\Http\Controllers\Controller;
use App\Http\Requests\Materials\StoreMaterialRequest;
use App\Http\Resources\MaterialResource;
use Illuminate\Http\JsonResponse;

class StoreMaterialController extends Controller
{
    public function __invoke(StoreMaterialRequest $request, StoreMaterial $storeMaterial): JsonResponse
    {
        $material = $storeMaterial->handle($request->user(), $request->file('file'));

        return (new MaterialResource($material->refresh()))
            ->response()
            ->setStatusCode(201);
    }
}
