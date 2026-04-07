<?php

declare(strict_types=1);

namespace App\Domain\Collection\Enums;

enum CollectionStatus: string
{
    case None = 'none';
    case InProgress = 'in_progress';
    case Committed = 'committed';
    case Escalated = 'escalated';
    case WrittenOff = 'written_off';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::InProgress => 'In Progress',
            self::Committed => 'Committed',
            self::Escalated => 'Escalated',
            self::WrittenOff => 'Written Off',
            self::Resolved => 'Resolved',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::None => 'لا يوجد',
            self::InProgress => 'قيد التحصيل',
            self::Committed => 'التزام بالسداد',
            self::Escalated => 'تم التصعيد',
            self::WrittenOff => 'تم الشطب',
            self::Resolved => 'تم الحل',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::InProgress => 'blue',
            self::Committed => 'yellow',
            self::Escalated => 'red',
            self::WrittenOff => 'red',
            self::Resolved => 'green',
        };
    }
}
