<?php

namespace Tests\Feature;

use App\Enums\MaterialStatus;
use App\Jobs\ProcessMaterial;
use App\Models\Material;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Uploading, checking and removing files for the chat (specs/chat-attachments.md).
 */
class MaterialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_guest_cannot_use_materials(): void
    {
        $this->postJson('/api/v1/materials')->assertUnauthorized();
        $this->getJson('/api/v1/materials/1')->assertUnauthorized();
        $this->deleteJson('/api/v1/materials/1')->assertUnauthorized();
    }

    public function test_an_upload_is_kept_privately_and_queued_for_reading(): void
    {
        Queue::fake();
        $learner = User::factory()->create();
        Sanctum::actingAs($learner);

        $response = $this->postJson('/api/v1/materials', [
            'file' => $this->file('Lecture 1.pdf', "%PDF-1.4\nnotes"),
        ]);

        $response->assertCreated()
            ->assertJsonPath('original_filename', 'Lecture 1.pdf')
            ->assertJsonPath('size_bytes', 14)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('error', null)
            ->assertJsonMissingPath('extracted_text');

        $material = Material::sole();
        $this->assertTrue($material->user->is($learner));
        $this->assertNull($material->topic_id);
        $this->assertNull($material->message_id);
        $this->assertSame('application/pdf', $material->mime_type);
        $this->assertStringStartsWith("materials/{$learner->id}/", $material->storage_path);
        $this->assertStringEndsWith('.pdf', $material->storage_path);
        Storage::disk('local')->assertExists($material->storage_path);
        Queue::assertPushed(ProcessMaterial::class, fn (ProcessMaterial $job): bool => $job->material->is($material));
    }

    public function test_a_text_file_is_read_straight_after_uploading(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/materials', [
            'file' => $this->file('notes.md', "# Cadence\nSteps per minute."),
        ]);

        $response->assertCreated()->assertJsonPath('status', 'ready');
        $this->assertSame("# Cadence\nSteps per minute.", Material::sole()->extracted_text);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedFiles(): array
    {
        return [
            'pdf' => ['notes.pdf', "%PDF-1.4\nnotes"],
            'word' => ['notes.docx', self::zipContents()],
            'old word' => ['notes.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504)],
            'pages' => ['notes.pages', self::zipContents()],
            'text' => ['notes.txt', 'Steps per minute.'],
            'markdown' => ['NOTES.MD', '# Steps per minute'],
        ];
    }

    #[DataProvider('acceptedFiles')]
    public function test_pdf_word_pages_text_and_markdown_files_are_accepted(string $filename, string $contents): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/materials', ['file' => $this->file($filename, $contents)])
            ->assertCreated();
    }

    public function test_only_documents_up_to_10_mb_are_accepted(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/materials', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Pick a file to upload.']);
        $this->postJson('/api/v1/materials', ['file' => $this->file('screenshot.png', "\x89PNG\r\n\x1A\n".str_repeat("\0", 64))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Upload a PDF, Word, Pages, text or Markdown file.']);
        // The extension alone isn't enough: a program renamed to .pdf is refused on its contents.
        $this->postJson('/api/v1/materials', ['file' => $this->file('notes.pdf', "\x7FELF\x02\x01\x01".str_repeat("\0", 64))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Upload a PDF, Word, Pages, text or Markdown file.']);
        $this->postJson('/api/v1/materials', ['file' => $this->file('book.pdf', "%PDF-1.4\n".str_repeat('a', 10_241 * 1024))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Keep files under 10 MB.']);

        $this->assertDatabaseCount('materials', 0);
    }

    public function test_a_learner_sees_how_reading_their_file_went(): void
    {
        $learner = User::factory()->create();
        $failed = Material::factory()->unsent()->failed()->for($learner)->create();
        Sanctum::actingAs($learner);

        $this->getJson("/api/v1/materials/{$failed->id}")
            ->assertOk()
            ->assertJsonPath('id', $failed->id)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', "We couldn't read this file. Save it as a PDF and upload it again.");
    }

    public function test_someone_elses_file_is_not_found(): void
    {
        $someoneElses = Material::factory()->unsent()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/materials/{$someoneElses->id}")->assertNotFound();
        $this->deleteJson("/api/v1/materials/{$someoneElses->id}")->assertNotFound();
        $this->assertModelExists($someoneElses);
    }

    public function test_an_unsent_file_can_be_removed_with_its_file(): void
    {
        $learner = User::factory()->create();
        $material = Material::factory()->unsent()->for($learner)->create();
        Storage::disk('local')->put($material->storage_path, 'notes');
        Sanctum::actingAs($learner);

        $this->deleteJson("/api/v1/materials/{$material->id}")->assertNoContent();

        $this->assertModelMissing($material);
        Storage::disk('local')->assertMissing($material->storage_path);
    }

    public function test_a_sent_file_stays(): void
    {
        $learner = User::factory()->create();
        $material = Material::factory()->unsent()->for($learner)->create(['message_id' => Message::factory()]);
        Sanctum::actingAs($learner);

        $this->deleteJson("/api/v1/materials/{$material->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['material' => 'This file is part of a conversation, so it stays.']);
        $this->assertModelExists($material);
    }

    public function test_uploading_is_rate_limited_per_learner(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->postJson('/api/v1/materials', ['file' => $this->file('notes.txt', 'Notes')])->assertCreated();
        }

        $this->postJson('/api/v1/materials', ['file' => $this->file('notes.txt', 'Notes')])
            ->assertTooManyRequests()
            ->assertJsonPath('message', "You're uploading quickly. Wait a moment and try again.");
    }

    public function test_files_never_sent_are_pruned_after_a_day_with_their_file(): void
    {
        $learner = User::factory()->create();
        $forgotten = Material::factory()->unsent()->for($learner)->create(['created_at' => now()->subDay()->subMinute()]);
        $justUploaded = Material::factory()->unsent()->for($learner)->create(['created_at' => now()->subHours(23)]);
        $sent = Material::factory()->unsent()->for($learner)->create(['message_id' => Message::factory(), 'created_at' => now()->subWeek()]);
        $ofATopic = Material::factory()->create(['created_at' => now()->subWeek()]);
        Storage::disk('local')->put($forgotten->storage_path, 'notes');

        Artisan::call('model:prune', ['--model' => [Material::class]]);

        $this->assertModelMissing($forgotten);
        Storage::disk('local')->assertMissing($forgotten->storage_path);
        $this->assertModelExists($justUploaded);
        $this->assertModelExists($sent);
        $this->assertModelExists($ofATopic);
    }

    public function test_a_processing_file_has_no_text_yet(): void
    {
        $material = Material::factory()->unsent()->processing()->create();

        $this->assertSame(MaterialStatus::Processing, $material->status);
        $this->assertNull($material->extracted_text);
    }

    /**
     * A real file on disk, so its type is detected from its contents as with a real upload (a fake upload
     * would report the type of its extension).
     */
    private function file(string $filename, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $filename, test: true);
    }

    /**
     * Word (.docx) and Pages files are zip archives.
     */
    private static function zipContents(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('index.xml', '<document>notes</document>');
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);

        return $contents;
    }
}
