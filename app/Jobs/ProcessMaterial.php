<?php

namespace App\Jobs;

use App\Actions\ExtractTextFromFile;
use App\Enums\MaterialStatus;
use App\Exceptions\MaterialUnreadable;
use App\Models\Material;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads the text out of an uploaded file in the background (specs/chat-attachments.md), so the upload
 * answers right away. A file that can't be read fails at once with a reason for the learner; only
 * unexpected errors are tried again. A file removed in the meantime drops the job.
 */
#[DeleteWhenMissingModels]
#[Timeout(120)]
#[Tries(3)]
#[Backoff(10)]
class ProcessMaterial implements ShouldQueue
{
    use Queueable;

    public function __construct(public Material $material) {}

    public function handle(ExtractTextFromFile $extractTextFromFile): void
    {
        $material = $this->material;

        try {
            $text = $extractTextFromFile->handle(
                Storage::disk('local')->path($material->storage_path),
                pathinfo($material->original_filename, PATHINFO_EXTENSION),
            );
        } catch (MaterialUnreadable $exception) {
            $material->update(['status' => MaterialStatus::Failed, 'error' => $exception->getMessage()]);

            return;
        }

        $material->update(['status' => MaterialStatus::Ready, 'extracted_text' => $text, 'error' => null]);
    }

    /**
     * Every try failed on something unexpected, which is reported by the queue.
     */
    public function failed(?Throwable $exception): void
    {
        $this->material->update([
            'status' => MaterialStatus::Failed,
            'error' => MaterialUnreadable::unreadable()->getMessage(),
        ]);
    }
}
