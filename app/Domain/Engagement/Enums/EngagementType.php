<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

enum EngagementType: string
{
    case Audit = 'audit';
    case Review = 'review';
    case Compilation = 'compilation';
    case Tax = 'tax';
    case Bookkeeping = 'bookkeeping';
    case Consulting = 'consulting';

    public function label(): string
    {
        return match ($this) {
            self::Audit => 'Audit',
            self::Review => 'Review',
            self::Compilation => 'Compilation',
            self::Tax => 'Tax',
            self::Bookkeeping => 'Bookkeeping',
            self::Consulting => 'Consulting',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Audit => 'مراجعة',
            self::Review => 'فحص',
            self::Compilation => 'تجميع',
            self::Tax => 'ضرائب',
            self::Bookkeeping => 'مسك دفاتر',
            self::Consulting => 'استشارات',
        };
    }
}
