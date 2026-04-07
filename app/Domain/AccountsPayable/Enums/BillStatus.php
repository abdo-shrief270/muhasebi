<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Enums;

enum BillStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Approved => 'معتمد',
            self::PartiallyPaid => 'مدفوع جزئياً',
            self::Paid => 'مدفوع',
            self::Cancelled => 'ملغي',
        };
    }

    public function canApprove(): bool
    {
        return $this === self::Draft;
    }

    public function canPay(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyPaid], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
    }

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }
}
