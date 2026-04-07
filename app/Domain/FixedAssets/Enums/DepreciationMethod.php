<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Enums;

enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';
    case UnitsOfProduction = 'units_of_production';

    public function label(): string
    {
        return match ($this) {
            self::StraightLine => 'Straight Line',
            self::DecliningBalance => 'Declining Balance',
            self::UnitsOfProduction => 'Units of Production',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::StraightLine => 'القسط الثابت',
            self::DecliningBalance => 'القسط المتناقص',
            self::UnitsOfProduction => 'وحدات الإنتاج',
        };
    }
}
