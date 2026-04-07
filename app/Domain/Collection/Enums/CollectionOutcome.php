<?php

declare(strict_types=1);

namespace App\Domain\Collection\Enums;

enum CollectionOutcome: string
{
    case NoAnswer = 'no_answer';
    case PromisedPayment = 'promised_payment';
    case Disputed = 'disputed';
    case PartialPayment = 'partial_payment';
    case Paid = 'paid';
    case Escalated = 'escalated';

    public function label(): string
    {
        return match ($this) {
            self::NoAnswer => 'No Answer',
            self::PromisedPayment => 'Promised Payment',
            self::Disputed => 'Disputed',
            self::PartialPayment => 'Partial Payment',
            self::Paid => 'Paid',
            self::Escalated => 'Escalated',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::NoAnswer => 'لا رد',
            self::PromisedPayment => 'وعد بالسداد',
            self::Disputed => 'متنازع عليه',
            self::PartialPayment => 'سداد جزئي',
            self::Paid => 'تم السداد',
            self::Escalated => 'تم التصعيد',
        };
    }
}
