<?php

declare(strict_types=1);

namespace App\Domain\Investor\Enums;

enum DistributionStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Approved => 'معتمدة',
            self::Paid => 'مدفوعة',
        };
    }

    public function canApprove(): bool
    {
        return $this === self::Draft;
    }

    public function canMarkPaid(): bool
    {
        return $this === self::Approved;
    }

    public function canDelete(): bool
    {
        return $this === self::Draft;
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'green',
            self::Paid => 'emerald',
        };
    }
}
