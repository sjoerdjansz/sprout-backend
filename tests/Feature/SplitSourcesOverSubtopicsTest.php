<?php

namespace Tests\Feature;

use App\Actions\SplitMaterialOverSubtopics;
use App\Enums\AiCallKind;
use App\Enums\GenerationStatus;
use App\Enums\MessageRole;
use App\Exceptions\AiRequestFailed;
use App\Jobs\GenerateSubtopicMaterial;
use App\Jobs\SplitSourcesOverSubtopics;
use App\Models\Material;
use App\Models\Message;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

/**
 * Saving a conversation with files gives each subtopic its part of those files as notes, before its
 * flashcards and questions are made (specs/chat-attachments.md).
 */
class SplitSourcesOverSubtopicsTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    private const LECTURE = "# Cadence\n\nCadence is steps per minute.\n\nCourse logistics: exam on Friday.\n\n# Stride length\n\nStride length is how far each step carries you.";

    public function test_saving_with_a_read_file_splits_it_before_making_material(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $conversation = StudySession::factory()->for($learner)->create(['topic_id' => null, 'ended_at' => null]);
        $userMessage = Message::factory()->for($conversation)->create(['role' => MessageRole::User]);
        $tutorMessage = Message::factory()->for($conversation)->create(['role' => MessageRole::Tutor]);
        $lecture = Material::factory()->unsent()->for($learner)->create(['message_id' => $userMessage->id]);
        Material::factory()->unsent()->failed()->for($learner)->create(['message_id' => $userMessage->id]);
        Sanctum::actingAs($learner);

        $this->postJson("/api/v1/conversations/{$conversation->id}/save", [
            'title' => 'Running',
            'category_name' => 'Sport',
            'goal' => ['description' => 'Coach better', 'deadline' => now()->addMonth()->toDateString(), 'recall_percentage' => 80],
            'subtopics' => [
                ['title' => 'Cadence', 'content' => 'Steps per minute.'],
                ['title' => 'Stride length', 'content' => 'How far a step goes.'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('topic.subtopics.0.generation_status', 'generating')
            ->assertJsonPath('topic.subtopics.1.generation_status', 'generating');

        $topic = $conversation->fresh()->topic;
        Queue::assertNotPushed(GenerateSubtopicMaterial::class);
        Queue::assertPushed(SplitSourcesOverSubtopics::class, fn (SplitSourcesOverSubtopics $job): bool => $job->topic->is($topic)
            && $job->materialIds === [$lecture->id]
            && $job->subtopicIdsNeedingMaterial === $topic->subtopics()->orderBy('position')->pluck('id')->all()
            && $job->sourceMessageId === $tutorMessage->id);
    }

    public function test_each_subtopic_gets_its_part_of_the_file_word_for_word_and_then_its_material(): void
    {
        Queue::fake([GenerateSubtopicMaterial::class]);
        $learner = $this->learnerWithAiConfigured();
        [$topic, $cadence, $stride] = $this->topicWithSubtopics($learner);
        $lecture = Material::factory()->for($topic)->create(['original_filename' => 'Lecture 1.pdf', 'extracted_text' => self::LECTURE]);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [
            ['title' => 'Cadence', 'blocks' => [2, 1]],
            ['title' => 'stride length', 'blocks' => [4, 5]],
        ]])]);

        dispatch(new SplitSourcesOverSubtopics($topic, [$lecture->id], [$cadence->id], sourceMessageId: 9));

        $this->assertSame("Steps per minute.\n\n### From Lecture 1.pdf\n\n# Cadence\n\nCadence is steps per minute.", $cadence->fresh()->content);
        $this->assertSame("How far a step goes.\n\n### From Lecture 1.pdf\n\n# Stride length\n\nStride length is how far each step carries you.", $stride->fresh()->content);
        // Stride length already had material, but its notes changed, so it gets more.
        Queue::assertPushed(GenerateSubtopicMaterial::class, 2);
        Queue::assertPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->is($stride) && $job->sourceMessageId === 9);
        $this->assertSame(GenerationStatus::Generating, $stride->fresh()->generation_status);
    }

    public function test_the_ai_gets_numbered_blocks_and_every_subtopic_and_it_counts_as_a_split_source(): void
    {
        Queue::fake([GenerateSubtopicMaterial::class]);
        $learner = $this->learnerWithAiConfigured();
        [$topic, $cadence] = $this->topicWithSubtopics($learner);
        $lecture = Material::factory()->for($topic)->create(['extracted_text' => self::LECTURE]);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => []])]);

        dispatch(new SplitSourcesOverSubtopics($topic, [$lecture->id], [$cadence->id]));

        Http::assertSent(function (Request $request) use ($learner): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $request['model'] === config('ai.levels.medium.generation_model')
                && $request['safety_identifier'] === $learner->aiSafetyIdentifier()
                && $input['topic'] === ['title' => 'Running', 'goal' => 'Coach better']
                && $input['subtopics'] === [
                    ['title' => 'Cadence', 'notes' => 'Steps per minute.'],
                    ['title' => 'Stride length', 'notes' => 'How far a step goes.'],
                ]
                && count($input['blocks']) === 5
                && $input['blocks'][2] === ['number' => 3, 'text' => 'Course logistics: exam on Friday.'];
        });
        $this->assertSame(AiCallKind::SubtopicSuggestions, $learner->aiCalls()->sole()->kind);
        // Nothing fitted, so only the subtopic the save was for gets material, from the notes it has.
        Queue::assertPushed(GenerateSubtopicMaterial::class, 1);
        Queue::assertPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->is($cadence));
    }

    public function test_unknown_subtopics_and_blocks_are_ignored_and_a_block_goes_to_one_subtopic(): void
    {
        Queue::fake([GenerateSubtopicMaterial::class]);
        $learner = $this->learnerWithAiConfigured();
        [$topic, $cadence, $stride] = $this->topicWithSubtopics($learner);
        $lecture = Material::factory()->for($topic)->create(['original_filename' => 'Lecture 1.pdf', 'extracted_text' => self::LECTURE]);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [
            ['title' => 'Cadence', 'blocks' => [2, 2, 99]],
            ['title' => 'Breathing', 'blocks' => [3]],
            ['title' => 'Stride length', 'blocks' => [2]],
        ]])]);

        dispatch(new SplitSourcesOverSubtopics($topic, [$lecture->id], []));

        $this->assertSame("Steps per minute.\n\n### From Lecture 1.pdf\n\nCadence is steps per minute.", $cadence->fresh()->content);
        $this->assertSame('How far a step goes.', $stride->fresh()->content);
        Queue::assertPushed(GenerateSubtopicMaterial::class, 1);
    }

    public function test_every_file_is_split_and_each_part_gets_its_own_heading(): void
    {
        Queue::fake([GenerateSubtopicMaterial::class]);
        $learner = $this->learnerWithAiConfigured();
        [$topic, $cadence] = $this->topicWithSubtopics($learner);
        $lecture = Material::factory()->for($topic)->create(['original_filename' => 'Lecture 1.pdf', 'extracted_text' => 'Cadence is steps per minute.']);
        $syllabus = Material::factory()->for($topic)->create(['original_filename' => 'Syllabus.docx', 'extracted_text' => 'Week 1: cadence drills.']);
        $failed = Material::factory()->for($topic)->failed()->create();
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [['title' => 'Cadence', 'blocks' => [1]]]])]);

        dispatch(new SplitSourcesOverSubtopics($topic, [$lecture->id, $syllabus->id, $failed->id], [$cadence->id]));

        Http::assertSentCount(2);
        $this->assertSame(
            "Steps per minute.\n\n### From Lecture 1.pdf\n\nCadence is steps per minute.\n\n### From Syllabus.docx\n\nWeek 1: cadence drills.",
            $cadence->fresh()->content,
        );
    }

    public function test_lines_wrapped_by_pdftotext_are_joined_into_paragraphs_and_headings_stay_apart(): void
    {
        $pdfText = "Running Biomechanics - Lecture 3\n"
            ."Course information: the exam is on 12 December in room B204. Office hours are on\n"
            ."Tuesdays from 14:00 to 15:00.\n"
            ."Cadence\n"
            ."Cadence is the number of steps a runner takes per minute. Most recreational\n"
            ."runners land between 160 and 170 steps per minute.\n"
            ."\fA higher cadence usually means shorter steps.\n";

        $blocks = app(SplitMaterialOverSubtopics::class)->blocks($pdfText);

        $this->assertSame([
            1 => 'Running Biomechanics - Lecture 3',
            2 => 'Course information: the exam is on 12 December in room B204. Office hours are on Tuesdays from 14:00 to 15:00.',
            3 => 'Cadence',
            4 => 'Cadence is the number of steps a runner takes per minute. Most recreational runners land between 160 and 170 steps per minute.',
            5 => 'A higher cadence usually means shorter steps.',
        ], $blocks);
    }

    public function test_markdown_headings_and_list_items_are_blocks_of_their_own(): void
    {
        $markdown = "# Cadence\n- Steps per minute, usually between 160 and 180 for most runners\n- Higher cadence lowers braking forces at each foot strike\n\nSome closing words that run on and on without a full stop at the end of this line\nand end here.";

        $blocks = app(SplitMaterialOverSubtopics::class)->blocks($markdown);

        $this->assertSame([
            1 => '# Cadence',
            2 => '- Steps per minute, usually between 160 and 180 for most runners',
            3 => '- Higher cadence lowers braking forces at each foot strike',
            4 => 'Some closing words that run on and on without a full stop at the end of this line and end here.',
        ], $blocks);
    }

    public function test_a_paragraph_without_sentence_ends_is_cut_at_1500_characters(): void
    {
        $longLine = str_repeat('a', 900);

        $blocks = app(SplitMaterialOverSubtopics::class)->blocks("{$longLine}\n{$longLine}\n{$longLine}");

        $this->assertSame([1 => "{$longLine} {$longLine}", 2 => $longLine], $blocks);
    }

    public function test_notes_never_grow_past_10000_characters(): void
    {
        Queue::fake([GenerateSubtopicMaterial::class]);
        $learner = $this->learnerWithAiConfigured();
        [$topic, $cadence] = $this->topicWithSubtopics($learner);
        $cadence->update(['content' => str_repeat('n', 6_000)]);
        $paragraph = str_repeat('p', 1_400);
        $lecture = Material::factory()->for($topic)->create(['extracted_text' => implode("\n\n", array_fill(0, 5, $paragraph))]);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [['title' => 'Cadence', 'blocks' => [1, 2, 3, 4, 5]]]])]);

        dispatch(new SplitSourcesOverSubtopics($topic, [$lecture->id], []));

        $notes = $cadence->fresh()->content;
        $this->assertLessThanOrEqual(SplitSourcesOverSubtopics::MAX_NOTES_CHARACTERS, Str::length($notes));
        $this->assertSame(2, substr_count($notes, $paragraph));
    }

    public function test_when_every_try_fails_the_material_is_made_from_the_notes_as_they_are(): void
    {
        Queue::fake([GenerateSubtopicMaterial::class]);
        $learner = $this->learnerWithAiConfigured();
        [$topic, $cadence] = $this->topicWithSubtopics($learner);
        $lecture = Material::factory()->for($topic)->create(['extracted_text' => self::LECTURE]);

        (new SplitSourcesOverSubtopics($topic, [$lecture->id], [$cadence->id], sourceMessageId: 9))->failed(AiRequestFailed::unavailable());

        $this->assertSame('Steps per minute.', $cadence->fresh()->content);
        Queue::assertPushed(GenerateSubtopicMaterial::class, 1);
        Queue::assertPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->is($cadence) && $job->sourceMessageId === 9);
    }

    public function test_a_failed_ai_call_writes_nothing_so_a_retry_never_adds_a_part_twice(): void
    {
        Queue::fake([GenerateSubtopicMaterial::class]);
        $learner = $this->learnerWithAiConfigured();
        [$topic, $cadence] = $this->topicWithSubtopics($learner);
        $lecture = Material::factory()->for($topic)->create(['extracted_text' => 'Cadence is steps per minute.']);
        $syllabus = Material::factory()->for($topic)->create(['extracted_text' => 'Week 1: cadence drills.']);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->pushResponse($this->openAiAnswer(['subtopics' => [['title' => 'Cadence', 'blocks' => [1]]]]))
            ->pushResponse($this->openAiError(500, 'server_error'))]);

        $this->expectException(AiRequestFailed::class);

        try {
            app()->call([new SplitSourcesOverSubtopics($topic, [$lecture->id, $syllabus->id], [$cadence->id]), 'handle']);
        } finally {
            $this->assertSame('Steps per minute.', $cadence->fresh()->content);
            Queue::assertNothingPushed();
        }
    }

    /**
     * @return array{0: Topic, 1: Subtopic, 2: Subtopic}
     */
    private function topicWithSubtopics(User $learner): array
    {
        $topic = Topic::factory()->for($learner)->create(['title' => 'Running', 'goal' => 'Coach better']);
        $cadence = Subtopic::factory()->for($topic)->create(['title' => 'Cadence', 'content' => 'Steps per minute.', 'position' => 1, 'generation_status' => GenerationStatus::Generating]);
        $stride = Subtopic::factory()->for($topic)->create(['title' => 'Stride length', 'content' => 'How far a step goes.', 'position' => 2, 'generation_status' => GenerationStatus::Ready]);

        return [$topic, $cadence, $stride];
    }
}
