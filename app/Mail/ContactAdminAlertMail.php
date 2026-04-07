<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Cms\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactAdminAlertMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ContactSubmission $submission,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "رسالة تواصل جديدة: {$this->submission->subject}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-admin-alert',
        );
    }
}
