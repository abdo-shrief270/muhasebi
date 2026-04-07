<?php

declare(strict_types=1);

namespace App\Domain\Collection\Enums;

enum CollectionActionType: string
{
    case Call = 'call';
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Meeting = 'meeting';
    case LegalNotice = 'legal_notice';
    case WriteOff = 'write_off';
    case PaymentCommitment = 'payment_commitment';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Phone Call',
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::Whatsapp => 'WhatsApp',
            self::Meeting => 'Meeting',
            self::LegalNotice => 'Legal Notice',
            self::WriteOff => 'Write-Off',
            self::PaymentCommitment => 'Payment Commitment',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Call => 'مكالمة هاتفية',
            self::Email => 'بريد إلكتروني',
            self::Sms => 'رسالة نصية',
            self::Whatsapp => 'واتساب',
            self::Meeting => 'اجتماع',
            self::LegalNotice => 'إنذار قانوني',
            self::WriteOff => 'إعدام دين',
            self::PaymentCommitment => 'التزام بالسداد',
        };
    }
}
