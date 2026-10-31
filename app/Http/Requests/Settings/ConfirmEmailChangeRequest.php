<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmEmailChangeRequest extends FormRequest
{
    /**
     * The token proves the learner owns the new address, so the link also works on a device where
     * they aren't signed in.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'This link is incomplete. Change your email again from your settings.',
            'token.string' => 'This link is incomplete. Change your email again from your settings.',
        ];
    }
}
