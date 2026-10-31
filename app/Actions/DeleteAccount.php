<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a learner's account and everything in it, right away (specs/settings.md, GDPR). Every
 * foreign key cascades from users, except Sanctum's polymorphic tokens, which are deleted here, and
 * uploaded files, which live on disk.
 */
class DeleteAccount
{
    public function handle(User $user): void
    {
        $materialPaths = $user->materials()->pluck('storage_path');

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        // Files go once the rows are gone, so a failed delete never leaves rows pointing at missing files.
        Storage::disk('local')->delete($materialPaths->all());
    }
}
