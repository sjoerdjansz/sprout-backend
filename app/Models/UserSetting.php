<?php

namespace App\Models;

use App\Enums\Interest;
use Database\Factories\UserSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learner's preferences: one row per user, created at sign up.
 */
#[Fillable(['user_id', 'interests', 'wants_daily_reminder', 'timezone', 'daily_goal_cards', 'reminder_time'])]
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
            'wants_daily_reminder' => 'boolean',
            'daily_goal_cards' => 'integer',
        ];
    }
}
