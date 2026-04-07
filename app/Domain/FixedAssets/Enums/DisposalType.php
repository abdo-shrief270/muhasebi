<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Enums;

enum DisposalType: string
{
    case Sale = 'sale';
    case Scrap = 'scrap';
    case Donation = 'donation';
    case WriteOff = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Scrap => 'Scrap',
            self::Donation => 'Donation',
            self::WriteOff => 'Write Off',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Sale => 'بيع',
            self::Scrap => 'خردة',
            self::Donation => 'تبرع',
            self::WriteOff => 'شطب',
        };
    }
}
