<?php

namespace App\Http\Requests\Conversations;

use App\Http\Requests\CategoryMessages;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveConversationRequest extends FormRequest
{
    /**
     * Ownership is checked when the conversation is looked up: someone else's is a 404.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The reviewed draft. A topic can't be saved without a complete SMART goal and one subtopic. The
     * category is optional here because a continued topic keeps its own; the action requires one
     * for a new topic.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'prohibits:category_name', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)],
            'category_name' => ['nullable', 'string', 'max:60'],
            'goal' => ['required', 'array:description,deadline,recall_percentage'],
            'goal.description' => ['required', 'string', 'max:1000'],
            'goal.deadline' => ['required', 'date_format:Y-m-d', 'after:today'],
            'goal.recall_percentage' => ['required', 'integer', 'between:1,100'],
            'subtopics' => ['required', 'array', 'min:1', 'max:30'],
            'subtopics.*' => ['array:title,content'],
            'subtopics.*.title' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'subtopics.*.content' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Give your topic a name.',
            'title.max' => 'Keep the topic name under 255 characters.',
            ...CategoryMessages::MESSAGES,
            'goal.required' => 'Set a goal for this topic first.',
            'goal.array' => 'Set a goal for this topic first.',
            'goal.description.required' => 'Describe what you want to know or be able to do.',
            'goal.description.max' => 'Keep your goal under 1,000 characters.',
            'goal.deadline.required' => 'Pick a deadline for your goal.',
            'goal.deadline.date_format' => 'Pick a deadline for your goal.',
            'goal.deadline.after' => 'Pick a deadline after today.',
            'goal.recall_percentage.required' => 'Choose how much you want to remember, from 1 to 100%.',
            'goal.recall_percentage.integer' => 'Choose how much you want to remember, from 1 to 100%.',
            'goal.recall_percentage.between' => 'Choose how much you want to remember, from 1 to 100%.',
            'subtopics.required' => 'Add at least one subtopic.',
            'subtopics.array' => 'Add at least one subtopic.',
            'subtopics.min' => 'Add at least one subtopic.',
            'subtopics.max' => 'A topic can have up to 30 subtopics. Save the rest as a new topic.',
            'subtopics.*.array' => 'Add at least one subtopic.',
            'subtopics.*.title.required' => 'Give every subtopic a name.',
            'subtopics.*.title.max' => 'Keep subtopic names under 255 characters.',
            'subtopics.*.title.distinct' => 'Two subtopics have the same name. Rename or remove one.',
            'subtopics.*.content.required' => 'Add notes to every subtopic, so we can make cards from them.',
            'subtopics.*.content.max' => 'Keep the notes of a subtopic under 10,000 characters.',
        ];
    }

    /**
     * @return array<int, array{title: string, content: string}>
     */
    public function subtopics(): array
    {
        return array_map(fn (array $subtopic): array => [
            'title' => $subtopic['title'],
            'content' => $subtopic['content'],
        ], array_values($this->validated('subtopics')));
    }
}
