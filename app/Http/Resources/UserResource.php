<?php

namespace App\Http\Resources;

use App\Enums\Interest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            // Sign up preferences live in user_settings; the response shape stays as documented in API.md.
            'interests' => $this->settings?->interests?->map(fn (Interest $interest): string => $interest->value)->values() ?? [],
            'wants_daily_reminder' => $this->settings?->wants_daily_reminder ?? false,
            'created_at' => $this->created_at,
        ];
    }
}
