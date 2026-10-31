<?php

namespace App\Models;

use App\Enums\AiCallKind;
use Database\Factories\AiCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI call that answered, logged by RequestAiCompletion. The learner's usage is counted from these.
 */
#[Fillable(['user_id', 'kind', 'model', 'input_tokens', 'output_tokens'])]
class AiCall extends Model
{
    /** @use HasFactory<AiCallFactory> */
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
            'kind' => AiCallKind::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }
}
