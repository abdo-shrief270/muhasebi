<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Enums;

enum InstallmentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Waived => 'Waived',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Pending => 'معلق',
            self::Paid => 'مدفوع',
            self::Overdue => 'متأخر',
            self::Waived => 'معفى',
        };
    }
}
