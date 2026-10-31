<?php

namespace App\Http\Requests\Questions;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An answer in the retrieval quiz: a picked option (multiple choice), a typed answer (open) or "I don't
 * know". Which of these the question needs is checked in AnswerQuestion, which knows its type.
 */
class AnswerQuestionRequest extends FormRequest
{
    /**
     * Ownership of the question is checked when it is looked up: someone else's is a 404.
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
            'option_id' => ['nullable', 'integer'],
            'response' => ['nullable', 'string', 'max:5000'],
            'second_try' => ['sometimes', 'boolean'],
            'dont_know' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'option_id.integer' => 'Pick one of this question\'s options.',
            'response.string' => 'Write your answer first.',
            'response.max' => 'Keep your answer under 5,000 characters.',
            'second_try.boolean' => 'Say whether this is your second try: true or false.',
            'dont_know.boolean' => 'Say whether you don\'t know the answer: true or false.',
        ];
    }
}
