<?php

declare(strict_types=1);

namespace App\Domain\Tax\Enums;

enum TaxAdjustmentType: string
{
    case Addition = 'addition';
    case Subtraction = 'subtraction';

    public function label(): string
    {
        return match ($this) {
            self::Addition => 'Addition',
            self::Subtraction => 'Subtraction',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Addition => 'إضافة',
            self::Subtraction => 'خصم',
        };
    }
}
