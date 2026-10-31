<?php

namespace App\Http\Resources;

use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The learner's preferences on the settings page (specs/settings.md).
 *
 * @mixin UserSetting
 */
class SettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'learner_role' => $this->learner_role?->value,
            'avatar_color' => $this->avatar_color->value,
            'ai_level' => $this->ai_level->value,
            'daily_goal_cards' => $this->daily_goal_cards,
            'session_size' => $this->session_size,
            'interleave_subtopics' => $this->interleave_subtopics,
            'hint_before_answer' => $this->hint_before_answer,
            'save_chats_as_material' => $this->save_chats_as_material,
            'wants_daily_reminder' => $this->wants_daily_reminder,
            // Stored with seconds; the settings page picks hours and minutes.
            'reminder_time' => substr($this->reminder_time, 0, 5),
            'wants_streak_reminder' => $this->wants_streak_reminder,
            'wants_weekly_insights' => $this->wants_weekly_insights,
            'reminder_channel' => $this->reminder_channel->value,
            'theme' => $this->theme->value,
            'reduce_motion' => $this->reduce_motion,
            'sound_effects' => $this->sound_effects,
            'timezone' => $this->timezone,
        ];
    }
}
