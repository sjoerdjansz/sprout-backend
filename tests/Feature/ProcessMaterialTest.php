<?php

namespace Tests\Feature;

use App\Actions\ExtractTextFromFile;
use App\Enums\MaterialStatus;
use App\Jobs\ProcessMaterial;
use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reading the text out of an uploaded file (specs/chat-attachments.md). pdftotext and LibreOffice are faked:
 * the tests check what Sprout asks them and what it does with their answer.
 */
class ProcessMaterialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_text_and_markdown_files_are_read_as_they_are(): void
    {
        Process::fake();
        $material = $this->uploaded('notes.md', "\u{FEFF}# Cadence\nSteps per minute.\n\n");

        dispatch(new ProcessMaterial($material));

        $material->refresh();
        $this->assertSame(MaterialStatus::Ready, $material->status);
        $this->assertSame("# Cadence\nSteps per minute.", $material->extracted_text);
        $this->assertNull($material->error);
        Process::assertNothingRan();
    }

    public function test_text_saved_by_older_windows_editors_becomes_utf8(): void
    {
        $material = $this->uploaded('notes.txt', "Caf\xE9 notes");

        dispatch(new ProcessMaterial($material));

        $this->assertSame('Café notes', $material->fresh()->extracted_text);
    }

    public function test_a_pdf_is_read_with_pdftotext(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result(output: "Cadence\fStride length\n"));
        $material = $this->uploaded('Lecture 1.pdf', '%PDF-1.4');

        dispatch(new ProcessMaterial($material));

        $material->refresh();
        $this->assertSame(MaterialStatus::Ready, $material->status);
        $this->assertSame("Cadence\fStride length", $material->extracted_text);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'pdftotext', '-enc', 'UTF-8', Storage::disk('local')->path($material->storage_path), '-',
        ]);
    }

    public function test_word_and_pages_files_are_read_with_libreoffice(): void
    {
        foreach (['notes.docx', 'notes.doc', 'notes.pages'] as $filename) {
            Process::fake(function (PendingProcess $process) {
                $this->writeLibreOfficeOutput($process, "\u{FEFF}Cadence is steps per minute.");

                return Process::result();
            });
            $material = $this->uploaded($filename, 'contents');

            dispatch(new ProcessMaterial($material));

            $material->refresh();
            $this->assertSame(MaterialStatus::Ready, $material->status, $filename);
            $this->assertSame('Cadence is steps per minute.', $material->extracted_text);
            Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'soffice'
                && in_array('txt:Text (encoded):UTF8', $process->command, true)
                && end($process->command) === Storage::disk('local')->path($material->storage_path));
        }
    }

    public function test_a_file_libreoffice_cannot_open_fails_with_advice(): void
    {
        // LibreOffice exits with 0 but writes nothing when it can't open a file.
        Process::fake(fn (PendingProcess $process) => Process::result());
        $material = $this->uploaded('notes.pages', 'contents');

        dispatch(new ProcessMaterial($material));

        $material->refresh();
        $this->assertSame(MaterialStatus::Failed, $material->status);
        $this->assertNull($material->extracted_text);
        $this->assertSame("We couldn't read this file. Save it as a PDF and upload it again.", $material->error);
    }

    public function test_a_damaged_pdf_fails_with_advice(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result(errorOutput: 'Syntax Error: Couldn\'t find trailer dictionary', exitCode: 1));
        $material = $this->uploaded('notes.pdf', '%PDF-1.4');

        dispatch(new ProcessMaterial($material));

        $this->assertSame("We couldn't read this file. Save it as a PDF and upload it again.", $material->fresh()->error);
    }

    public function test_a_scan_without_text_fails_with_advice(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result(output: "\f\f  \n"));
        $material = $this->uploaded('scan.pdf', '%PDF-1.4');

        dispatch(new ProcessMaterial($material));

        $material->refresh();
        $this->assertSame(MaterialStatus::Failed, $material->status);
        $this->assertSame("We couldn't find any text in this file. If it's a scan, copy the text into a file and upload that.", $material->error);
    }

    public function test_more_than_60000_characters_is_too_long(): void
    {
        $exactlyEnough = $this->uploaded('chapter.txt', str_repeat('é', ExtractTextFromFile::MAX_CHARACTERS));
        $tooLong = $this->uploaded('book.txt', str_repeat('é', ExtractTextFromFile::MAX_CHARACTERS + 1));

        dispatch(new ProcessMaterial($exactlyEnough));
        dispatch(new ProcessMaterial($tooLong));

        $this->assertSame(MaterialStatus::Ready, $exactlyEnough->fresh()->status);
        $this->assertSame(MaterialStatus::Failed, $tooLong->fresh()->status);
        $this->assertSame('This file is too long for Sprout to read at once. Upload the chapter you need.', $tooLong->fresh()->error);
    }

    public function test_when_every_try_fails_unexpectedly_the_file_is_marked_as_failed(): void
    {
        $material = $this->uploaded('notes.txt', 'Notes');

        (new ProcessMaterial($material))->failed(new \RuntimeException('Disk unavailable'));

        $material->refresh();
        $this->assertSame(MaterialStatus::Failed, $material->status);
        $this->assertSame("We couldn't read this file. Save it as a PDF and upload it again.", $material->error);
    }

    private function uploaded(string $filename, string $contents): Material
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $material = Material::factory()->unsent()->processing()->create([
            'original_filename' => $filename,
            'storage_path' => 'materials/'.fake()->uuid().'.'.$extension,
        ]);
        Storage::disk('local')->put($material->storage_path, $contents);

        return $material;
    }

    /**
     * Writes what LibreOffice would: <outdir>/<the file's name>.txt.
     */
    private function writeLibreOfficeOutput(PendingProcess $process, string $text): void
    {
        $command = $process->command;
        $outputDirectory = $command[array_search('--outdir', $command, true) + 1];

        file_put_contents($outputDirectory.'/'.pathinfo(end($command), PATHINFO_FILENAME).'.txt', $text);
    }
}
