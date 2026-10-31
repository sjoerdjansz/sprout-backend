<?php

namespace App\Http\Controllers\Settings;

use App\Actions\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RequestEmailChangeRequest;
use Illuminate\Http\JsonResponse;

class RequestEmailChangeController extends Controller
{
    public function __invoke(RequestEmailChangeRequest $request, RequestEmailChange $requestEmailChange): JsonResponse
    {
        $requestEmailChange->handle($request->user(), $request->validated('email'));

        return response()->json(['message' => 'Check your new inbox and open the link to confirm.'], 202);
    }
}
