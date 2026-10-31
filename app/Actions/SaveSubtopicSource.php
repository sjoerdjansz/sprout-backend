<?php

namespace App\Actions;

use App\Enums\SourceType;
use App\Models\Material;
use App\Models\Subtopic;

/**
 * Writes one source of a subtopic (specs/subtopic-sources.md): its tutor notes, its own notes, or the part
 * of a file. Empty content removes the source. Says whether there is something new to make material from,
 * so the caller can queue it.
 */
class SaveSubtopicSource
{
    /**
     * @param  Material|null  $material  the file, for a file source
     * @return bool true when the source was added or its content changed; removing it is never new
     */
    public function handle(Subtopic $subtopic, SourceType $type, ?string $content, ?Material $material = null): bool
    {
        $existing = $subtopic->sources()
            ->where('type', $type)
            ->where('material_id', $material?->id)
            ->first();

        if (blank($content)) {
            $existing?->delete();
            $subtopic->unsetRelation('sources');

            return false;
        }

        if ($existing !== null && trim($existing->content) === trim($content)) {
            return false;
        }

        $subtopic->sources()->updateOrCreate(
            ['type' => $type, 'material_id' => $material?->id],
            ['content' => $content],
        );
        $subtopic->unsetRelation('sources');

        return true;
    }
}
