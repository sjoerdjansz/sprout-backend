<?php

namespace App\Models;

use App\Enums\AiProvider;
use Database\Factories\AiCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The learner's own AI key (bring your own key). The key is encrypted at rest and never serialized.
 */
#[Hidden(['encrypted_api_key'])]
#[Fillable(['user_id', 'provider', 'encrypted_api_key', 'key_last_four', 'model', 'validated_at'])]
class AiCredential extends Model
{
    /** @use HasFactory<AiCredentialFactory> */
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
            'provider' => AiProvider::class,
            'encrypted_api_key' => 'encrypted',
            'validated_at' => 'datetime',
        ];
    }
}
