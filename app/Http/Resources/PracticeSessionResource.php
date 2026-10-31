<?php

namespace App\Http\Resources;

use App\Models\StudySession;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A practice session with what was done in it (see SummarisePracticeSession).
 *
 * @property array{session: StudySession, is_finished: bool, last_activity_at: CarbonInterface, done: array<string, mixed>, xp_earned: int, streak_days: int, subtopics: array<int, array<string, mixed>>} $resource
 */
class PracticeSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $session = $this->resource['session'];

        return [
            'id' => $session->id,
            'scope' => $session->practiceScope(),
            'topic' => $session->topic === null ? null : ['id' => $session->topic->id, 'title' => $session->topic->title],
            'subtopic' => $session->subtopic === null ? null : ['id' => $session->subtopic->id, 'title' => $session->subtopic->title],
            'plan' => $session->plan,
            'started_at' => $session->started_at,
            'ended_at' => $session->ended_at,
            'last_activity_at' => $this->resource['last_activity_at'],
            'is_finished' => $this->resource['is_finished'],
            'done' => $this->resource['done'],
            'xp_earned' => $this->resource['xp_earned'],
            'streak_days' => $this->resource['streak_days'],
            'subtopics' => $this->resource['subtopics'],
        ];
    }
}
