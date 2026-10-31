<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\Flashcard;
use App\Models\Material;
use App\Models\Question;
use App\Models\Subtopic;
use App\Models\SubtopicSource;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
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

    public function test_guest_cannot_remove_a_source(): void
    {
        $this->deleteJson('/api/v1/subtopics/1/sources/1')->assertUnauthorized();
    }

    public function test_any_source_can_be_removed_and_its_cards_and_questions_stay(): void
    {
        Queue::fake();
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        $subtopic = Subtopic::factory()->for($topic)->create();
        $lecture = Material::factory()->for($topic)->create();
        $sources = [
            SubtopicSource::factory()->for($subtopic)->tutor()->create(),
            SubtopicSource::factory()->for($subtopic)->own()->create(),
            SubtopicSource::factory()->for($subtopic)->file($lecture)->create(),
        ];
        $flashcard = Flashcard::factory()->for($learner)->for($topic)->for($subtopic)->create();
        $question = Question::factory()->for($topic)->for($subtopic)->create();
        Sanctum::actingAs($learner);

        foreach ($sources as $source) {
            $this->deleteJson("/api/v1/subtopics/{$subtopic->id}/sources/{$source->id}")->assertNoContent();
            $this->assertModelMissing($source);
        }

        $this->assertModelExists($flashcard);
        $this->assertModelExists($question);
        // A file part only leaves the subtopic: the file stays with the topic.
        $this->assertModelExists($lecture);
        Queue::assertNothingPushed();
        $this->getJson("/api/v1/subtopics/{$subtopic->id}")->assertJsonPath('source_count', 0)->assertJsonPath('notes', null);
    }

    public function test_someone_elses_source_or_one_of_another_subtopic_is_not_found(): void
    {
        $learner = User::factory()->create();
        $subtopic = Subtopic::factory()->for(Topic::factory()->for($learner))->create();
        $otherSubtopic = Subtopic::factory()->for(Topic::factory()->for($learner))->create();
        $ofOtherSubtopic = SubtopicSource::factory()->for($otherSubtopic)->create();
        $someoneElses = SubtopicSource::factory()->create();
        Sanctum::actingAs($learner);

        $this->deleteJson("/api/v1/subtopics/{$subtopic->id}/sources/{$ofOtherSubtopic->id}")->assertNotFound();
        $this->deleteJson("/api/v1/subtopics/{$someoneElses->subtopic_id}/sources/{$someoneElses->id}")->assertNotFound();
        $this->assertModelExists($ofOtherSubtopic);
        $this->assertModelExists($someoneElses);
    }
}
