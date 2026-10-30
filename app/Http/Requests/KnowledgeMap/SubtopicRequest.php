<?php

namespace App\Http\Requests\KnowledgeMap;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or editing a subtopic from the knowledge map. Both send the whole form; `topic_id` is the
 * main topic, so editing it moves the subtopic.
 */
class SubtopicRequest extends FormRequest
{
    /**
     * Ownership of an edited subtopic is checked when it is looked up: someone else's is a 404.
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
            'topic_id' => ['required', 'integer', Rule::exists('topics', 'id')->where('user_id', $this->user()->id)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'topic_id.required' => 'Pick the main topic this subtopic belongs to.',
            'topic_id.integer' => "We couldn't find that topic. Pick one from your knowledge map.",
            'topic_id.exists' => "We couldn't find that topic. Pick one from your knowledge map.",
            'title.required' => 'Give your subtopic a name.',
            'title.max' => 'Keep subtopic names under 255 characters.',
            'description.max' => 'Keep the description under 1,000 characters.',
            'notes.max' => 'Keep the notes of a subtopic under 10,000 characters.',
        ];
    }
}
