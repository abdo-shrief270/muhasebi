<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $amount,
        public readonly string $invoiceNumber,
        public readonly string $paymentMethod,
        public readonly string $actionUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "تم استلام دفعة للفاتورة {$this->invoiceNumber} - محاسبي",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-received',
        );
    }
}