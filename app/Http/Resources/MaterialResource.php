<?php

namespace App\Http\Resources;

use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An uploaded file and how far Sprout got reading it. The text itself stays on the server.
 *
 * @mixin Material
 */
class MaterialResource extends JsonResource
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
            'original_filename' => $this->original_filename,
            'size_bytes' => $this->size_bytes,
            'status' => $this->status->value,
            'error' => $this->error,
            'created_at' => $this->created_at,
        ];
    }
}
