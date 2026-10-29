<?php

namespace App\Http\Requests\Auth;

use App\Enums\Interest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Signing up is open to guests.
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
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'interests' => ['sometimes', 'array'],
            'interests.*' => ['distinct', Rule::enum(Interest::class)],
            'wants_daily_reminder' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Tell us what to call you.',
            'name.max' => 'Keep your name under 50 characters.',
            'email.required' => 'Enter your email address.',
            'email.email' => "That doesn't look like an email address. Check it and try again.",
            'email.max' => 'That email address is too long. Use a shorter one.',
            'interests.array' => 'Pick your interests from the list.',
            'interests.*' => 'Pick your interests from the list.',
            'wants_daily_reminder.boolean' => 'Turn the daily reminder on or off.',
        ];
    }

    /**
     * @return array<int, Interest>
     */
    public function interests(): array
    {
        return array_map(
            fn (string $interest): Interest => Interest::from($interest),
            array_values(array_unique($this->validated('interests', []))),
        );
    }

    /**
     * Emails are stored lowercase so "Ada@Example.com" and "ada@example.com" are one account.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }
}
