<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the old address once an email change is confirmed, so a learner notices a change they didn't make.
 */
class EmailChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $newEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Sprout email has changed',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-changed',
        );
    }
}
