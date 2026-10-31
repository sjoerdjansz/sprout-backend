<?php

namespace App\Http\Requests\Practice;

use App\Enums\SessionKind;
use App\Models\StudySession;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * For the practice endpoints (card ratings, quiz answers, explanations): the optional practice session the
 * rating or answer belongs to. Only the learner's own session that hasn't been finished counts.
 */
trait LinksPracticeSession
{
    public const PRACTICE_SESSION_ENDED = 'This practice session has ended. Start a new one to keep practising.';

    /**
     * @return array<string, array<int, string|Exists>>
     */
    protected function practiceSessionRules(): array
    {
        return [
            'practice_session_id' => [
                'nullable',
                'integer',
                Rule::exists('study_sessions', 'id')
                    ->where('user_id', $this->user()->id)
                    ->where('kind', SessionKind::Review->value)
                    ->whereNull('ended_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function practiceSessionMessages(): array
    {
        return [
            'practice_session_id.integer' => self::PRACTICE_SESSION_ENDED,
            'practice_session_id.exists' => self::PRACTICE_SESSION_ENDED,
        ];
    }

    public function practiceSession(): ?StudySession
    {
        $practiceSessionId = $this->validated('practice_session_id');

        return $practiceSessionId === null ? null : $this->user()->practiceSessions()->findOrFail($practiceSessionId);
    }
}
