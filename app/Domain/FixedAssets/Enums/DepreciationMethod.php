<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Enums;

enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';

    public function label(): string
    {
        return match ($this) {
            self::StraightLine => 'Straight Line',
            self::DecliningBalance => 'Declining Balance',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::StraightLine => 'القسط الثابت',
            self::DecliningBalance => 'القسط المتناقص',
        };
    }
}
