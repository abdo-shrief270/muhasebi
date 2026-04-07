<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

enum OvertimeType: string
{
    case Weekday = 'weekday';
    case Friday = 'friday';

    public function label(): string
    {
        return match ($this) {
            self::Weekday => 'Weekday Overtime',
            self::Friday => 'Friday / Rest Day Overtime',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Weekday => 'إضافي أيام العمل',
            self::Friday => 'إضافي يوم الراحة',
        };
    }

    /** Multiplier per Egyptian Labor Law No. 12/2003, Articles 85-86 */
    public function rate(): float
    {
        return match ($this) {
            self::Weekday => 1.35,
            self::Friday => 2.0,
        };
    }
}
