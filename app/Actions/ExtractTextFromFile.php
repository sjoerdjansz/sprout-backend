<?php

namespace App\Actions;

use App\Exceptions\MaterialUnreadable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Reads the text out of an uploaded file (specs/chat-attachments.md), locally: pdftotext for PDF and
 * LibreOffice for Word and Pages. No AI is involved, so reading a file costs nothing and counts nowhere.
 */
class ExtractTextFromFile
{
    /** About 25 pages: a chapter or a long article, which the tutor and card generation can read in one go. */
    public const MAX_CHARACTERS = 60_000;

    private const BYTE_ORDER_MARK = "\u{FEFF}";

    /**
     * @param  string  $path  absolute path to the file
     * @param  string  $extension  the file's extension as uploaded (pdf, docx, doc, pages, txt or md)
     *
     * @throws MaterialUnreadable
     */
    public function handle(string $path, string $extension): string
    {
        $text = match (Str::lower($extension)) {
            'txt', 'md' => $this->readPlainText($path),
            'pdf' => $this->readPdf($path),
            'docx', 'doc', 'pages' => $this->readWithLibreOffice($path),
            default => throw MaterialUnreadable::unreadable(),
        };

        // pdftotext ends every page with a form feed, so a scan without text is only form feeds.
        $text = trim(Str::replace(self::BYTE_ORDER_MARK, '', $text), " \t\n\r\0\x0B\f");

        if ($text === '') {
            throw MaterialUnreadable::noText();
        }

        if (mb_strlen($text) > self::MAX_CHARACTERS) {
            throw MaterialUnreadable::tooLong();
        }

        return $text;
    }

    private function readPlainText(string $path): string
    {
        $text = (string) file_get_contents($path);

        // Notes saved by older Windows editors are often Windows-1252 rather than UTF-8.
        if (! mb_check_encoding($text, 'UTF-8')) {
            return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return $text;
    }

    private function readPdf(string $path): string
    {
        $result = Process::timeout(60)->run(['pdftotext', '-enc', 'UTF-8', $path, '-']);

        if ($result->failed()) {
            Log::warning('pdftotext could not read an uploaded file.', ['error' => $result->errorOutput()]);

            throw MaterialUnreadable::unreadable();
        }

        return $result->output();
    }

    private function readWithLibreOffice(string $path): string
    {
        // LibreOffice needs a writable profile, and a profile of its own per run so runs can't block each other.
        $workingDirectory = sys_get_temp_dir().'/sprout-extract-'.Str::uuid();
        File::ensureDirectoryExists($workingDirectory);

        try {
            $result = Process::timeout(60)
                ->env(['HOME' => $workingDirectory])
                ->run(['soffice', '--headless', '--convert-to', 'txt:Text (encoded):UTF8', '--outdir', $workingDirectory, $path]);

            $outputPath = $workingDirectory.'/'.pathinfo($path, PATHINFO_FILENAME).'.txt';

            // LibreOffice exits with 0 even when it couldn't open the file; then it writes nothing.
            if ($result->failed() || ! File::exists($outputPath)) {
                Log::warning('LibreOffice could not read an uploaded file.', ['error' => $result->errorOutput()]);

                throw MaterialUnreadable::unreadable();
            }

            return File::get($outputPath);
        } finally {
            File::deleteDirectory($workingDirectory);
        }
    }
}
