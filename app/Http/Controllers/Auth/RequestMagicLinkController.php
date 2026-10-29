<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RequestMagicLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestMagicLinkRequest;
use Illuminate\Http\JsonResponse;

class RequestMagicLinkController extends Controller
{
    public function __invoke(RequestMagicLinkRequest $request, RequestMagicLink $requestMagicLink): JsonResponse
    {
        $requestMagicLink->handle($request->validated('email'));

        return response()->json(['message' => 'Check your inbox for your magic link.'], 202);
    }
}
