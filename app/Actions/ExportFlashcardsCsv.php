<?php

namespace App\Actions;

use App\Models\Flashcard;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The learner's flashcards as CSV for Anki: front, back and a tag per topic and subtopic, without a
 * header row, so Anki's "Import file" maps the columns straight away.
 */
class ExportFlashcardsCsv
{
    public function handle(User $user): string
    {
        $flashcards = $user->flashcards()->with('topic', 'subtopic')->orderBy('topic_id')->orderBy('id')->get();

        $file = fopen('php://temp', 'r+');

        foreach ($flashcards as $flashcard) {
            fputcsv($file, [$flashcard->front, $flashcard->back, $this->tags($flashcard)], escape: '');
        }

        rewind($file);
        $csv = stream_get_contents($file);
        fclose($file);

        return $csv;
    }

    /**
     * Anki separates tags with spaces and nests them with "::", e.g. "Photosynthesis::Light_reactions".
     */
    private function tags(Flashcard $flashcard): string
    {
        $tag = $this->tagPart($flashcard->topic->title);

        if ($flashcard->subtopic !== null) {
            $tag .= '::'.$this->tagPart($flashcard->subtopic->title);
        }

        return $tag;
    }

    private function tagPart(string $title): string
    {
        return Str::of($title)->squish()->replace([' ', ':'], '_')->toString();
    }
}
