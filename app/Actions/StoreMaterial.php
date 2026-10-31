<?php

namespace App\Actions;

use App\Enums\MaterialStatus;
use App\Jobs\ProcessMaterial;
use App\Models\Material;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Keeps an uploaded file privately and queues reading its text (specs/chat-attachments.md). It isn't
 * sent anywhere yet: that happens when the learner sends it with a message.
 */
class StoreMaterial
{
    public function handle(User $learner, UploadedFile $file): Material
    {
        // The extension as uploaded tells the reader what the file is; a guessed one would call a Pages file a zip.
        $extension = Str::lower($file->getClientOriginalExtension());
        $storagePath = $file->storeAs("materials/{$learner->id}", Str::uuid().'.'.$extension, 'local');

        $material = $learner->materials()->create([
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'status' => MaterialStatus::Processing,
        ]);

        ProcessMaterial::dispatch($material);

        return $material;
    }
}
