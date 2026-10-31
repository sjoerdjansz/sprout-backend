<?php

namespace App\Http\Requests\KnowledgeMap;

use App\Actions\ExplainSubtopic;
use App\Http\Requests\Practice\LinksPracticeSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An Explain it explanation of a subtopic: the first version, or the simpler second one.
 */
class ExplainSubtopicRequest extends FormRequest
{
    use LinksPracticeSession;

    /**
     * Ownership of the subtopic is checked when it is looked up: someone else's is a 404.
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
            ...$this->practiceSessionRules(),
            'explanation' => ['required', 'string', 'max:5000'],
            'version' => ['required', 'integer', 'in:'.ExplainSubtopic::FIRST_VERSION.','.ExplainSubtopic::SECOND_VERSION],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->practiceSessionMessages(),
            'explanation.required' => 'Write your explanation first.',
            'explanation.string' => 'Write your explanation first.',
            'explanation.max' => 'Keep your explanation under 5,000 characters.',
            'version.required' => 'Say which version this is: 1 for your first explanation, 2 for the simpler one.',
            'version.integer' => 'Say which version this is: 1 for your first explanation, 2 for the simpler one.',
            'version.in' => 'Say which version this is: 1 for your first explanation, 2 for the simpler one.',
        ];
    }
}
