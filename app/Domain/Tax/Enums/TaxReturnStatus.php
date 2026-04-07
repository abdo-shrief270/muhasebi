<?php

declare(strict_types=1);

namespace App\Domain\Tax\Enums;

enum TaxReturnStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Filed = 'filed';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Calculated => 'Calculated',
            self::Filed => 'Filed',
            self::Paid => 'Paid',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Calculated => 'محسوبة',
            self::Filed => 'مقدمة',
            self::Paid => 'مدفوعة',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Calculated => 'yellow',
            self::Filed => 'blue',
            self::Paid => 'green',
        };
    }
}
