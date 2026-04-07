<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInviteMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $userName,
        public readonly string $userEmail,
        public readonly string $userRole,
        public readonly string $inviterName,
        public readonly string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "دعوة للانضمام إلى فريق العمل - محاسبي",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invite',
        );
    }
}