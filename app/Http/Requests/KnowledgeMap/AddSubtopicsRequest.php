<?php

namespace App\Http\Requests\KnowledgeMap;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding the subtopics a learner kept from the suggestions for a topic's source, in one go. The
 * action checks them against the topic's existing subtopics and the 30-subtopic limit.
 */
class AddSubtopicsRequest extends FormRequest
{
    /**
     * Ownership of the topic is checked when it is looked up: someone else's is a 404.
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
            'subtopics' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'subtopics.*' => ['array:title,description,notes'],
            'subtopics.*.title' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'subtopics.*.description' => ['nullable', 'string', 'max:1000'],
            'subtopics.*.notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subtopics.required' => 'Keep at least one subtopic to add.',
            'subtopics.array' => 'Keep at least one subtopic to add.',
            'subtopics.list' => 'Keep at least one subtopic to add.',
            'subtopics.min' => 'Keep at least one subtopic to add.',
            'subtopics.max' => 'A topic can have up to 30 subtopics. Leave some out.',
            'subtopics.*.array' => 'Keep at least one subtopic to add.',
            'subtopics.*.title.required' => 'Give every subtopic a name.',
            'subtopics.*.title.string' => 'Give every subtopic a name.',
            'subtopics.*.title.max' => 'Keep subtopic names under 255 characters.',
            'subtopics.*.title.distinct' => 'Two subtopics have the same name. Rename or remove one.',
            'subtopics.*.description.max' => 'Keep the description under 1,000 characters.',
            'subtopics.*.notes.max' => 'Keep the notes of a subtopic under 10,000 characters.',
        ];
    }

    /**
     * @return array<int, array{title: string, description: ?string, notes: ?string}>
     */
    public function subtopics(): array
    {
        return array_map(fn (array $subtopic): array => [
            'title' => trim($subtopic['title']),
            'description' => $subtopic['description'] ?? null,
            'notes' => $subtopic['notes'] ?? null,
        ], array_values($this->validated('subtopics')));
    }
}
