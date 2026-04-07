<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

use Carbon\Carbon;

enum RecurringFrequency: int
{
    case Daily = 10;
    case Weekly = 20;
    case Monthly = 30;
    case Quarterly = 40;
    case Annually = 50;

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Annually => 'Annually',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Daily => 'يومي',
            self::Weekly => 'أسبوعي',
            self::Monthly => 'شهري',
            self::Quarterly => 'ربع سنوي',
            self::Annually => 'سنوي',
        };
    }

    public function nextDate(Carbon $from): Carbon
    {
        return match ($this) {
            self::Daily => $from->copy()->addDay(),
            self::Weekly => $from->copy()->addWeek(),
            self::Monthly => $from->copy()->addMonth(),
            self::Quarterly => $from->copy()->addMonths(3),
            self::Annually => $from->copy()->addYear(),
        };
    }
}
