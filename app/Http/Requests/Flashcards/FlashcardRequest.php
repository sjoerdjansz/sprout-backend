<?php

namespace App\Http\Requests\Flashcards;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding or editing a flashcard by hand. Both send the front and the back.
 */
class FlashcardRequest extends FormRequest
{
    /**
     * Ownership of the subtopic or card is checked when it is looked up: someone else's is a 404.
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
            'front' => ['required', 'string', 'max:500'],
            'back' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'front.required' => 'Write the question or prompt for the front.',
            'front.string' => 'Write the question or prompt for the front.',
            'front.max' => 'Keep the front under 500 characters.',
            'back.required' => 'Write the answer for the back.',
            'back.string' => 'Write the answer for the back.',
            'back.max' => 'Keep the back under 2,000 characters.',
        ];
    }
}
