<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The chat history, or with `topic_id` only the conversations about one of the learner's topics
 * (the subtopic page's Sessions tab).
 */
class ListConversationsRequest extends FormRequest
{
    /**
     * Any signed in learner may list their conversations; the topic rule keeps it to their own topics.
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
            'topic_id' => ['sometimes', 'integer', Rule::exists('topics', 'id')->where('user_id', $this->user()->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'topic_id.integer' => "We couldn't find that topic. Pick one from your knowledge map.",
            'topic_id.exists' => "We couldn't find that topic. Pick one from your knowledge map.",
        ];
    }
}
