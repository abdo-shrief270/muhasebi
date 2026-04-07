<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Enums;

enum DisposalType: string
{
    case Sale = 'sale';
    case Scrapping = 'scrapping';
    case Donation = 'donation';
    case Loss = 'loss';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Scrapping => 'Scrapping',
            self::Donation => 'Donation',
            self::Loss => 'Loss',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Sale => 'بيع',
            self::Scrapping => 'تخريد',
            self::Donation => 'تبرع',
            self::Loss => 'خسارة',
        };
    }
}
