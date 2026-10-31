<?php

namespace App\Actions;

use App\Models\Material;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Removes a file the learner uploaded but hasn't sent. A sent file is part of the conversation (and
 * later the topic), so it stays.
 */
class DeleteMaterial
{
    public const ALREADY_SENT_MESSAGE = 'This file is part of a conversation, so it stays.';

    /**
     * @throws ValidationException when the file was already sent.
     */
    public function handle(Material $material): void
    {
        if ($material->isSent()) {
            throw ValidationException::withMessages(['material' => self::ALREADY_SENT_MESSAGE]);
        }

        $material->delete();

        // The file goes once the row is gone, so a failed delete never leaves a row pointing at a missing file.
        Storage::disk('local')->delete($material->storage_path);
    }
}
