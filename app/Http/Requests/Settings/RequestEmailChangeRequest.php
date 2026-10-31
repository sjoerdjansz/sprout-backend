<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RequestEmailChangeRequest extends FormRequest
{
    /**
     * A learner only ever changes their own email.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Saying that an address is taken reveals that it has an account. That is fine here: only a signed in
     * learner can ask, the endpoint is rate limited, and otherwise they'd wait for a link that never comes.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::notIn([$this->user()->email]),
                Rule::unique('users', 'email'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter your new email address.',
            'email.string' => 'Enter your new email address.',
            'email.email' => "That doesn't look like an email address. Check it and try again.",
            'email.max' => 'That email address is too long. Use a shorter one.',
            'email.not_in' => "That's already your email address.",
            'email.unique' => 'That email already has an account. Use another one.',
        ];
    }

    /**
     * Emails are stored lowercase, as at sign up.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }
}
