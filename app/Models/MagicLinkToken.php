<?php

namespace App\Models;

use Database\Factories\MagicLinkTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use sign in link. Only the SHA-256 hash of the token is stored, so a
 * leaked database can't be used to sign in.
 */
#[Fillable(['user_id', 'token_hash', 'expires_at'])]
class MagicLinkToken extends Model
{
    /** @use HasFactory<MagicLinkTokenFactory> */
    use HasFactory;

    public const LIFETIME_IN_MINUTES = 15;

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<MagicLinkToken>  $query
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
