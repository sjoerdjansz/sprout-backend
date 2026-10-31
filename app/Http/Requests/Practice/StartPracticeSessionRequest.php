<?php

namespace App\Http\Requests\Practice;

use App\Enums\PracticeMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a practice session: what to practise (a topic or one of its subtopics), how (the modes) and
 * the cards and questions the frontend picked for its blocks. Whether those fit the scope is checked in
 * StartPracticeSession.
 */
class StartPracticeSessionRequest extends FormRequest
{
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
        $learnersTopics = Rule::exists('topics', 'id')->where('user_id', $this->user()->id);

        return [
            'topic_id' => ['required', 'integer', $learnersTopics],
            'subtopic_id' => ['nullable', 'integer', Rule::exists('subtopics', 'id')],
            'modes' => ['required', 'array', 'min:1'],
            'modes.*' => ['distinct', Rule::enum(PracticeMode::class)],
            'card_ids' => ['present', 'array', 'max:100'],
            'card_ids.*' => ['integer', 'distinct'],
            'question_ids' => ['present', 'array', 'max:100'],
            'question_ids.*' => ['integer', 'distinct'],
            'explain_subtopic_id' => ['nullable', 'integer', Rule::exists('subtopics', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'topic_id.required' => 'Pick one of your topics to practise.',
            'topic_id.integer' => 'Pick one of your topics to practise.',
            'topic_id.exists' => 'Pick one of your topics to practise.',
            'subtopic_id.integer' => 'Pick a subtopic of this topic.',
            'subtopic_id.exists' => 'Pick a subtopic of this topic.',
            'modes.required' => 'Pick at least one way to practise.',
            'modes.array' => 'Pick at least one way to practise.',
            'modes.min' => 'Pick at least one way to practise.',
            'modes.*.distinct' => 'Pick each way to practise only once.',
            'modes.*.enum' => 'Pick a way to practise: cards, retrieval, feynman or open.',
            'card_ids.present' => 'Send the cards for this session, or an empty list.',
            'card_ids.array' => 'Send the cards for this session, or an empty list.',
            'card_ids.max' => 'A session can have up to 100 cards.',
            'card_ids.*.integer' => 'Pick cards from what you\'re practising.',
            'card_ids.*.distinct' => 'Put each card in the session only once.',
            'question_ids.present' => 'Send the questions for this session, or an empty list.',
            'question_ids.array' => 'Send the questions for this session, or an empty list.',
            'question_ids.max' => 'A session can have up to 100 questions.',
            'question_ids.*.integer' => 'Pick quiz questions from what you\'re practising.',
            'question_ids.*.distinct' => 'Put each question in the session only once.',
            'explain_subtopic_id.integer' => 'Pick a subtopic from what you\'re practising to explain.',
            'explain_subtopic_id.exists' => 'Pick a subtopic from what you\'re practising to explain.',
        ];
    }
}
