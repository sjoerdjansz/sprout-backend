<?php

namespace App\Mail;

use App\Models\EmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the new address when a learner changes their email. Sent synchronously, like the magic link.
 */
class ConfirmEmailChangeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $confirmUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your new email for Sprout',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.confirm-email-change',
            with: ['lifetimeInMinutes' => EmailChange::LIFETIME_IN_MINUTES],
        );
    }
}
