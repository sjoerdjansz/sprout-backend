<?php

namespace App\Mail;

use App\Models\MagicLinkToken;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent synchronously: there is no queue worker in the Docker setup yet, and the
 * learner is waiting for this email.
 */
class MagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $magicLinkUrl,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Sprout sign in link',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.magic-link',
            with: ['lifetimeInMinutes' => MagicLinkToken::LIFETIME_IN_MINUTES],
        );
    }
}
