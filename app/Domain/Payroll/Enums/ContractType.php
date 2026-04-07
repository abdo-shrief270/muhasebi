<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

enum ContractType: string
{
    case Definite = 'definite';
    case Indefinite = 'indefinite';
    case PartTime = 'part_time';
    case Temporary = 'temporary';

    public function label(): string
    {
        return match ($this) {
            self::Definite => 'Definite Term',
            self::Indefinite => 'Indefinite Term',
            self::PartTime => 'Part Time',
            self::Temporary => 'Temporary',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Definite => 'محدد المدة',
            self::Indefinite => 'غير محدد المدة',
            self::PartTime => 'دوام جزئي',
            self::Temporary => 'مؤقت',
        };
    }
}
