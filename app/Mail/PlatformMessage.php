<?php

namespace App\Mail;

use App\Helpers\Qs;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A platform message from the Super Admin (Workstream DF) — sent
 * from the platform mail composer to a user, all School Admins, or
 * all accounts of a role. Platform communication only: the template
 * carries no school data.
 */
class PlatformMessage extends Mailable
{
    use Queueable, SerializesModels;

    public $subjectLine;
    public $bodyText;

    public function __construct(string $subject, string $body)
    {
        $this->subjectLine = $subject;
        $this->bodyText = $body;
    }

    public function envelope(): Envelope
    {
        // From = the platform's own system email setting (the mail
        // config's from address is blank in the shipped .env, and a
        // blank From makes transports fail).
        $from = Qs::getSetting('system_email') ?: 'info@hackstra.app';

        return new Envelope(
            from: new Address($from, 'Hackstra'),
            subject: $this->subjectLine
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform_message',
            text: 'emails.platform_message_plain',
            with: ['subjectLine' => $this->subjectLine, 'bodyText' => $this->bodyText]
        );
    }
}
