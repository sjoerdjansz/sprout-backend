<?php

namespace App\Http\Controllers\Materials;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaterialResource;
use Illuminate\Http\Request;

class ShowMaterialController extends Controller
{
    public function __invoke(Request $request, int $material): MaterialResource
    {
        // Looked up among the learner's own files, so someone else's is a 404.
        return new MaterialResource($request->user()->materials()->findOrFail($material));
    }
}
