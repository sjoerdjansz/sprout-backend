<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sprout couldn't get usable text out of an uploaded file. The message is shown to the learner as the
 * material's error, so it says what to do next. Trying again doesn't help: the file stays as it is.
 */
class MaterialUnreadable extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * Damaged, password protected, or a format the tools can't open.
     */
    public static function unreadable(): self
    {
        return new self("We couldn't read this file. Save it as a PDF and upload it again.");
    }

    /**
     * A scanned PDF has pictures of text, which the tools don't read.
     */
    public static function noText(): self
    {
        return new self("We couldn't find any text in this file. If it's a scan, copy the text into a file and upload that.");
    }

    public static function tooLong(): self
    {
        return new self('This file is too long for Sprout to read at once. Upload the chapter you need.');
    }
}
