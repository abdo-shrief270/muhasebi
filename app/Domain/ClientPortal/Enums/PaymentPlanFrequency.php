<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Enums;

enum PaymentPlanFrequency: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Biweekly => 'Biweekly',
            self::Monthly => 'Monthly',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Weekly => 'أسبوعي',
            self::Biweekly => 'كل أسبوعين',
            self::Monthly => 'شهري',
        };
    }

    public function intervalDays(): int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Biweekly => 14,
            self::Monthly => 30,
        };
    }
}
