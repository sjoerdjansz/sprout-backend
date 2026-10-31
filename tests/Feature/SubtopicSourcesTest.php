<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\Material;
use App\Models\Subtopic;
use App\Models\SubtopicSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A subtopic's sources: the tutor's notes, your own notes and parts of files (specs/subtopic-sources.md).
 */
class SubtopicSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ai_reads_every_source_under_a_heading_tutor_first_then_your_notes_then_files(): void
    {
        $subtopic = Subtopic::factory()->create();
        $syllabus = Material::factory()->for($subtopic->topic)->create(['original_filename' => 'Syllabus.docx']);
        $lecture = Material::factory()->for($subtopic->topic)->create(['original_filename' => 'Lecture 1.pdf']);
        SubtopicSource::factory()->for($subtopic)->file($lecture)->create(['content' => 'Cadence is steps per minute.']);
        SubtopicSource::factory()->for($subtopic)->own()->create(['content' => "My coach says 170.\n"]);
        SubtopicSource::factory()->for($subtopic)->file($syllabus)->create(['content' => 'Week 1: cadence.']);
        SubtopicSource::factory()->for($subtopic)->tutor()->create(['content' => 'Steps per minute.']);

        $subtopic->load('sources.material');

        $this->assertSame(
            ['tutor', 'own', 'file', 'file'],
            $subtopic->sources->map(fn (SubtopicSource $source): string => $source->type->value)->all(),
        );
        $this->assertSame(
            "## From the chat\n\nSteps per minute.\n\n"
            ."## Your notes\n\nMy coach says 170.\n\n"
            ."## From Syllabus.docx\n\nWeek 1: cadence.\n\n"
            ."## From Lecture 1.pdf\n\nCadence is steps per minute.",
            $subtopic->sourceText(),
        );
        $this->assertSame('Steps per minute.', $subtopic->sourceContent(SourceType::Tutor));
    }

    public function test_a_subtopic_without_sources_has_no_text(): void
    {
        $subtopic = Subtopic::factory()->create();

        $this->assertSame('', $subtopic->sourceText());
        $this->assertNull($subtopic->sourceContent(SourceType::Own));
    }

    public function test_sources_go_with_their_subtopic_and_file_parts_with_their_file(): void
    {
        $subtopic = Subtopic::factory()->create();
        $lecture = Material::factory()->for($subtopic->topic)->create();
        $tutorNotes = SubtopicSource::factory()->for($subtopic)->tutor()->create();
        $filePart = SubtopicSource::factory()->for($subtopic)->file($lecture)->create();

        $lecture->delete();
        $this->assertModelMissing($filePart);
        $this->assertModelExists($tutorNotes);

        $subtopic->delete();
        $this->assertModelMissing($tutorNotes);
    }
}
