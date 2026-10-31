<?php

namespace App\Http\Resources;

use App\Models\Answer;
use App\Models\Category;
use App\Models\DailyActivity;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Message;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything a learner has in Sprout, as a file they can keep. Ids are included so the file can be
 * read back: flashcards and questions point to their subtopic, conversations to their topic.
 * Expects the relations ExportLearnerData loads.
 *
 * @mixin User
 */
class LearnerExportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'exported_at' => now(),
            'profile' => [
                'name' => $this->name,
                'email' => $this->email,
                'created_at' => $this->created_at,
            ],
            'settings' => $this->settings === null ? null : [
                ...(new SettingsResource($this->settings))->toArray($request),
                'interests' => $this->settings->interests?->pluck('value')->values() ?? [],
            ],
            'categories' => $this->categories->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ]),
            'topics' => $this->topics->map(fn (Topic $topic): array => [
                'id' => $topic->id,
                'category_id' => $topic->category_id,
                'title' => $topic->title,
                'description' => $topic->description,
                'notes' => $topic->notes,
                'goal' => $topic->goal,
                'goal_deadline' => $topic->goal_deadline?->toDateString(),
                'goal_recall_percentage' => $topic->goal_recall_percentage,
                'archived_at' => $topic->archived_at,
                'created_at' => $topic->created_at,
                'subtopics' => $topic->subtopics->map(fn (Subtopic $subtopic): array => [
                    'id' => $subtopic->id,
                    'title' => $subtopic->title,
                    'description' => $subtopic->description,
                    'notes' => $subtopic->content,
                    'position' => $subtopic->position,
                    'mastery' => $subtopic->progress->first()?->mastery,
                    'last_studied_at' => $subtopic->progress->first()?->last_studied_at,
                ]),
                'flashcards' => $topic->flashcards->map(fn (Flashcard $flashcard): array => [
                    'id' => $flashcard->id,
                    'subtopic_id' => $flashcard->subtopic_id,
                    'front' => $flashcard->front,
                    'back' => $flashcard->back,
                    'origin' => $flashcard->origin->value,
                    'due_at' => $flashcard->due_at,
                    'interval_days' => $flashcard->interval_days,
                    'ease_factor' => (float) $flashcard->ease_factor,
                    'repetitions' => $flashcard->repetitions,
                    'lapses' => $flashcard->lapses,
                    'paused_at' => $flashcard->suspended_at,
                    'created_at' => $flashcard->created_at,
                    'reviews' => $flashcard->reviews->map(fn (FlashcardReview $review): array => [
                        'rating' => $review->rating->value,
                        'interval_days_before' => $review->interval_days_before,
                        'interval_days_after' => $review->interval_days_after,
                        'reviewed_at' => $review->reviewed_at,
                    ]),
                ]),
                'questions' => $topic->questions->map(fn (Question $question): array => [
                    'id' => $question->id,
                    'subtopic_id' => $question->subtopic_id,
                    'type' => $question->type->value,
                    'prompt' => $question->prompt,
                    'model_answer' => $question->model_answer,
                    'origin' => $question->origin->value,
                    'created_at' => $question->created_at,
                    'options' => $question->options->map(fn (QuestionOption $option): array => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'is_correct' => $option->is_correct,
                        'explanation' => $option->explanation,
                    ]),
                    'answers' => $question->answers->map(fn (Answer $answer): array => [
                        'question_option_id' => $answer->question_option_id,
                        'response' => $answer->response,
                        'score' => $answer->score,
                        'is_correct' => $answer->is_correct,
                        'feedback' => $answer->feedback,
                        'gaps' => $answer->gaps,
                        'answered_at' => $answer->answered_at,
                    ]),
                ]),
            ]),
            'conversations' => $this->studySessions->map(fn (StudySession $session): array => [
                'id' => $session->id,
                'topic_id' => $session->topic_id,
                'kind' => $session->kind->value,
                'started_at' => $session->started_at,
                'ended_at' => $session->ended_at,
                'messages' => $session->messages->map(fn (Message $message): array => [
                    'role' => $message->role->value,
                    'content' => $message->content,
                    'technique' => $message->technique?->value,
                    'created_at' => $message->created_at,
                ]),
            ]),
            'daily_activity' => $this->dailyActivities->map(fn (DailyActivity $activity): array => [
                'date' => $activity->activity_date->toDateString(),
                'xp_earned' => $activity->xp_earned,
                'cards_reviewed' => $activity->cards_reviewed,
                'questions_answered' => $activity->questions_answered,
            ]),
        ];
    }
}
