<?php

namespace App\Models;

use App\Enums\AiLevel;
use App\Enums\AvatarColor;
use App\Enums\Interest;
use App\Enums\LearnerRole;
use App\Enums\ReminderChannel;
use App\Enums\Theme;
use Database\Factories\UserSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learner's preferences: one row per user, created at sign up.
 */
#[Fillable([
    'user_id',
    'interests',
    'learner_role',
    'avatar_color',
    'ai_level',
    'wants_daily_reminder',
    'timezone',
    'daily_goal_cards',
    'session_size',
    'interleave_subtopics',
    'hint_before_answer',
    'save_chats_as_material',
    'reminder_time',
    'wants_streak_reminder',
    'wants_weekly_insights',
    'reminder_channel',
    'theme',
    'reduce_motion',
    'sound_effects',
])]
class UserSetting extends Model
{
    /** @use HasFactory<UserSettingFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interests' => AsEnumCollection::of(Interest::class),
            'learner_role' => LearnerRole::class,
            'avatar_color' => AvatarColor::class,
            'ai_level' => AiLevel::class,
            'wants_daily_reminder' => 'boolean',
            'daily_goal_cards' => 'integer',
            'session_size' => 'integer',
            'interleave_subtopics' => 'boolean',
            'hint_before_answer' => 'boolean',
            'save_chats_as_material' => 'boolean',
            'wants_streak_reminder' => 'boolean',
            'wants_weekly_insights' => 'boolean',
            'reminder_channel' => ReminderChannel::class,
            'theme' => Theme::class,
            'reduce_motion' => 'boolean',
            'sound_effects' => 'boolean',
        ];
    }
}
