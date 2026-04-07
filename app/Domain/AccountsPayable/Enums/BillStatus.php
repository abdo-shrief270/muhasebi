<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Enums;

enum BillStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::PartiallyPaid => 'Partially Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Approved => 'معتمدة',
            self::Paid => 'مدفوعة',
            self::PartiallyPaid => 'مدفوعة جزئياً',
            self::Cancelled => 'ملغاة',
        };
    }

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canApprove(): bool
    {
        return $this === self::Draft;
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'blue',
            self::Paid => 'green',
            self::PartiallyPaid => 'yellow',
            self::Cancelled => 'red',
        };
    }
}
