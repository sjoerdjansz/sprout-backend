<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Deleting an account can't be undone, so the learner types their email to confirm it.
 */
class DeleteAccountRequest extends FormRequest
{
    /**
     * A learner only ever deletes their own account.
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
            'email' => ['required', 'string', Rule::in([$this->user()->email])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Type your email address to confirm.',
            'email.string' => 'Type your email address to confirm.',
            'email.in' => 'Type your email address exactly as it is on your account.',
        ];
    }

    /**
     * Emails are stored lowercase, so "Ada@Example.com" confirms too.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }
}
