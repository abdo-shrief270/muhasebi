<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Paid => 'Paid',
            self::PartiallyPaid => 'Partially Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Sent => 'مرسلة',
            self::Paid => 'مدفوعة',
            self::PartiallyPaid => 'مدفوعة جزئياً',
            self::Overdue => 'متأخرة',
            self::Cancelled => 'ملغاة',
        };
    }

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canSend(): bool
    {
        return $this === self::Draft;
    }

    public function canPay(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyPaid, self::Overdue], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::Overdue], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'blue',
            self::Paid => 'green',
            self::PartiallyPaid => 'yellow',
            self::Overdue => 'red',
            self::Cancelled => 'red',
        };
    }
}
