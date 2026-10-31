<?php

namespace App\Http\Resources;

use App\Models\SubtopicSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One source of a subtopic for its Sources tab (specs/subtopic-sources.md). Expects the material loaded.
 *
 * @mixin SubtopicSource
 */
class SubtopicSourceResource extends JsonResource
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
            'type' => $this->type->value,
            'content' => $this->content,
            'material' => $this->material === null ? null : [
                'id' => $this->material->id,
                'original_filename' => $this->material->original_filename,
            ],
            'updated_at' => $this->updated_at,
        ];
    }
}
