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
            self::Draft => "\u{0645}\u{0633}\u{0648}\u{062f}\u{0629}",
            self::Calculated => "\u{0645}\u{062d}\u{0633}\u{0648}\u{0628}\u{0629}",
            self::Filed => "\u{0645}\u{0642}\u{062f}\u{0645}\u{0629}",
            self::Paid => "\u{0645}\u{062f}\u{0641}\u{0648}\u{0639}\u{0629}",
        };
    }

    public function canCalculate(): bool
    {
        return $this === self::Draft;
    }

    public function canFile(): bool
    {
        return $this === self::Calculated;
    }

    public function canPay(): bool
    {
        return $this === self::Filed;
    }
}
