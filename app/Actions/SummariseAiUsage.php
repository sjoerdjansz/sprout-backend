<?php

namespace App\Actions;

use App\Enums\AiCallKind;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The learner's AI calls this calendar month, in their timezone, per kind of call (specs/settings.md).
 * Sprout pays for the AI and there is no allowance yet, so this shows calls, not money or tokens.
 */
class SummariseAiUsage
{
    /**
     * @return array{month: string, tutor_replies: int, answers_checked: int, explain_feedback: int, card_batches: int, total: int}
     */
    public function handle(User $user): array
    {
        $startOfMonth = Carbon::now($user->timezone())->startOfMonth();

        $callsPerKind = $user->aiCalls()
            ->where('created_at', '>=', $startOfMonth->copy()->utc())
            ->selectRaw('kind, count(*) as calls')
            ->groupBy('kind')
            ->pluck('calls', 'kind');

        $countOf = fn (AiCallKind $kind): int => (int) ($callsPerKind[$kind->value] ?? 0);

        return [
            'month' => $startOfMonth->format('Y-m'),
            'tutor_replies' => $countOf(AiCallKind::TutorReply),
            'answers_checked' => $countOf(AiCallKind::AnswerCheck),
            'explain_feedback' => $countOf(AiCallKind::ExplainFeedback),
            'card_batches' => $countOf(AiCallKind::CardGeneration),
            'total' => (int) $callsPerKind->sum(),
        ];
    }
}
