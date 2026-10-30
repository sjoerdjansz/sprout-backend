<?php

namespace App\Http\Requests\KnowledgeMap;

use App\Http\Requests\CategoryMessages;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or editing a topic from the knowledge map. Both send the whole form.
 */
class TopicRequest extends FormRequest
{
    /**
     * Ownership of an edited topic is checked when it is looked up: someone else's is a 404.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A topic goes in one of the learner's categories (`category_id`) or a new one (`category_name`).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'category_id' => [
                'nullable',
                'required_without:category_name',
                'integer',
                'prohibits:category_name',
                Rule::exists('categories', 'id')->where('user_id', $this->user()->id),
            ],
            'category_name' => ['nullable', 'string', 'max:60'],
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
            'description.max' => 'Keep the description under 1,000 characters.',
            'notes.max' => 'Keep your notes under 10,000 characters.',
            ...CategoryMessages::MESSAGES,
        ];
    }
}
