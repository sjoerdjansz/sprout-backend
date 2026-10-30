<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartConversationRequest extends FormRequest
{
    /**
     * Any signed in learner may start a conversation; the topic rule keeps it to their own topics.
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
            'message' => SendMessageRequest::MESSAGE_RULES,
            'topic_id' => ['sometimes', 'nullable', 'integer', Rule::exists('topics', 'id')->where('user_id', $this->user()->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...SendMessageRequest::MESSAGE_MESSAGES,
            'topic_id.integer' => "We couldn't find that topic. Pick one from your knowledge map.",
            'topic_id.exists' => "We couldn't find that topic. Pick one from your knowledge map.",
        ];
    }
}
