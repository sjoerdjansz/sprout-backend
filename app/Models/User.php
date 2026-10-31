<?php

namespace App\Models;

use App\Enums\SessionKind;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return HasOne<UserSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /**
     * @return HasMany<MagicLinkToken, $this>
     */
    public function magicLinkTokens(): HasMany
    {
        return $this->hasMany(MagicLinkToken::class);
    }

    /**
     * Unused since 2026-10-31: Sprout uses its own AI key (ResolveAiKey). Kept until the table is dropped.
     *
     * @return HasMany<AiCredential, $this>
     */
    public function aiCredentials(): HasMany
    {
        return $this->hasMany(AiCredential::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<Topic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    /**
     * @return HasManyThrough<Subtopic, Topic, $this>
     */
    public function subtopics(): HasManyThrough
    {
        return $this->hasManyThrough(Subtopic::class, Topic::class);
    }

    /**
     * @return HasManyThrough<Question, Topic, $this>
     */
    public function questions(): HasManyThrough
    {
        return $this->hasManyThrough(Question::class, Topic::class);
    }

    /**
     * @return HasMany<StudySession, $this>
     */
    public function studySessions(): HasMany
    {
        return $this->hasMany(StudySession::class);
    }

    /**
     * The chats with the tutor ("conversations" in the API); review sessions belong to practice.
     *
     * @return HasMany<StudySession, $this>
     */
    public function conversations(): HasMany
    {
        return $this->studySessions()->where('kind', SessionKind::Learn);
    }

    /**
     * @return HasMany<Flashcard, $this>
     */
    public function flashcards(): HasMany
    {
        return $this->hasMany(Flashcard::class);
    }

    /**
     * @return HasMany<Answer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * @return HasMany<SubtopicProgress, $this>
     */
    public function subtopicProgress(): HasMany
    {
        return $this->hasMany(SubtopicProgress::class);
    }

    /**
     * @return HasMany<DailyActivity, $this>
     */
    public function dailyActivities(): HasMany
    {
        return $this->hasMany(DailyActivity::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * The learner's timezone: "today" and "due today" follow it.
     */
    public function timezone(): string
    {
        return $this->settings?->timezone ?? 'UTC';
    }

    /**
     * Sent with every AI request, so the provider can tell learners apart when it flags misuse; that
     * matters once requests share one business key. Keyed with the app key, because a plain hash of an
     * incrementing id is easy to reverse: the provider must never learn who the learner is.
     */
    public function aiSafetyIdentifier(): string
    {
        return hash_hmac('sha256', 'user:'.$this->id, config('app.key'));
    }
}
