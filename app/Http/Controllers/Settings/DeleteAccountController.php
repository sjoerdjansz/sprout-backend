<?php

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DeleteAccountRequest;
use Illuminate\Http\Response;

class DeleteAccountController extends Controller
{
    public function __invoke(DeleteAccountRequest $request, DeleteAccount $deleteAccount): Response
    {
        $deleteAccount->handle($request->user());

        return response()->noContent();
    }
}
