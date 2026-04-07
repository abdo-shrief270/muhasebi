<?php

declare(strict_types=1);

namespace App\Domain\Collection\Enums;

enum CollectionOutcome: string
{
    case PaymentCommitment = 'payment_commitment';
    case PartialPayment = 'partial_payment';
    case Disputed = 'disputed';
    case NoResponse = 'no_response';
    case RefusedToPay = 'refused_to_pay';
    case PaymentReceived = 'payment_received';
    case Escalated = 'escalated';
    case WrittenOff = 'written_off';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PaymentCommitment => 'Payment Commitment',
            self::PartialPayment => 'Partial Payment',
            self::Disputed => 'Disputed',
            self::NoResponse => 'No Response',
            self::RefusedToPay => 'Refused to Pay',
            self::PaymentReceived => 'Payment Received',
            self::Escalated => 'Escalated',
            self::WrittenOff => 'Written Off',
            self::Other => 'Other',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::PaymentCommitment => 'التزام بالسداد',
            self::PartialPayment => 'سداد جزئي',
            self::Disputed => 'متنازع عليها',
            self::NoResponse => 'لا يوجد رد',
            self::RefusedToPay => 'رفض السداد',
            self::PaymentReceived => 'تم السداد',
            self::Escalated => 'تم التصعيد',
            self::WrittenOff => 'تم الشطب',
            self::Other => 'أخرى',
        };
    }
}
