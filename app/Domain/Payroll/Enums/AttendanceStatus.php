<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case OnLeave = 'on_leave';
    case Holiday = 'holiday';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Late => 'Late',
            self::OnLeave => 'On Leave',
            self::Holiday => 'Holiday',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Present => 'حاضر',
            self::Absent => 'غائب',
            self::Late => 'متأخر',
            self::OnLeave => 'في إجازة',
            self::Holiday => 'عطلة',
        };
    }
}
