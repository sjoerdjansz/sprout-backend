<?php

namespace App\Models;

use Database\Factories\EmailChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending change of a learner's email address. The email is only changed once the link sent to the
 * new address is opened, because the email is how a learner signs in: a typo must not lock them out.
 * Like magic links, only the SHA-256 hash of the token is stored.
 */
#[Fillable(['user_id', 'new_email', 'token_hash', 'expires_at'])]
class EmailChange extends Model
{
    /** @use HasFactory<EmailChangeFactory> */
    use HasFactory;

    public const LIFETIME_IN_MINUTES = 60;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<EmailChange>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
