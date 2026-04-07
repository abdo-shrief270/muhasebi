<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum ValuationMethod: string
{
    case WeightedAverage = 'weighted_average';
    case Fifo = 'fifo';

    public function label(): string
    {
        return match ($this) {
            self::WeightedAverage => 'Weighted Average',
            self::Fifo => 'FIFO',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::WeightedAverage => 'المتوسط المرجح',
            self::Fifo => 'الوارد أولاً صادر أولاً',
        };
    }
}
