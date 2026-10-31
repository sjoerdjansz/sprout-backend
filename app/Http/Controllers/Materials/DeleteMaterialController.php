<?php

namespace App\Http\Controllers\Materials;

use App\Actions\DeleteMaterial;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteMaterialController extends Controller
{
    public function __invoke(Request $request, int $material, DeleteMaterial $deleteMaterial): Response
    {
        // Looked up among the learner's own files, so someone else's is a 404.
        $deleteMaterial->handle($request->user()->materials()->findOrFail($material));

        return response()->noContent();
    }
}
