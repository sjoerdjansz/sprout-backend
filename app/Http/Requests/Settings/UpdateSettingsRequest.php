<?php

namespace App\Http\Requests\Settings;

use App\Enums\AiLevel;
use App\Enums\AvatarColor;
use App\Enums\LearnerRole;
use App\Enums\ReminderChannel;
use App\Enums\Theme;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every field is optional, so each tab of the settings page sends only its own fields.
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * A learner only ever changes their own settings.
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
            'learner_role' => ['sometimes', Rule::enum(LearnerRole::class)],
            'avatar_color' => ['sometimes', Rule::enum(AvatarColor::class)],
            'ai_level' => ['sometimes', Rule::enum(AiLevel::class)],
            'daily_goal_cards' => ['sometimes', 'integer', Rule::in([10, 20, 40])],
            'session_size' => ['sometimes', 'integer', Rule::in([10, 20, 40])],
            'interleave_subtopics' => ['sometimes', 'boolean'],
            'hint_before_answer' => ['sometimes', 'boolean'],
            'save_chats_as_material' => ['sometimes', 'boolean'],
            'wants_daily_reminder' => ['sometimes', 'boolean'],
            'reminder_time' => ['sometimes', 'date_format:H:i'],
            'wants_streak_reminder' => ['sometimes', 'boolean'],
            'wants_weekly_insights' => ['sometimes', 'boolean'],
            'reminder_channel' => ['sometimes', Rule::enum(ReminderChannel::class)],
            'theme' => ['sometimes', Rule::enum(Theme::class)],
            'reduce_motion' => ['sometimes', 'boolean'],
            'sound_effects' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'timezone:all'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $onOrOff = 'Turn this on or off.';

        return [
            'learner_role' => 'Pick what brings you to Sprout from the list.',
            'avatar_color' => 'Pick a colour from the list.',
            'ai_level' => 'Pick low, medium or high.',
            'daily_goal_cards' => 'Pick a daily goal of 10, 20 or 40 cards.',
            'session_size' => 'Pick a session size of 10, 20 or 40.',
            'interleave_subtopics' => $onOrOff,
            'hint_before_answer' => $onOrOff,
            'save_chats_as_material' => $onOrOff,
            'wants_daily_reminder' => 'Turn the daily reminder on or off.',
            'reminder_time' => 'Pick a time like 19:00.',
            'wants_streak_reminder' => $onOrOff,
            'wants_weekly_insights' => $onOrOff,
            'reminder_channel' => 'Pick push, email or both.',
            'theme' => 'Pick light, dark or match system.',
            'reduce_motion' => $onOrOff,
            'sound_effects' => $onOrOff,
            'timezone' => "We don't know that timezone. Pick one from the list.",
        ];
    }
}
