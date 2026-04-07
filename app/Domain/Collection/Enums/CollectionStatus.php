<?php

declare(strict_types=1);

namespace App\Domain\Collection\Enums;

enum CollectionStatus: string
{
    case None = 'none';
    case InProgress = 'in_progress';
    case Escalated = 'escalated';
    case Legal = 'legal';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::InProgress => 'In Progress',
            self::Escalated => 'Escalated',
            self::Legal => 'Legal',
            self::WrittenOff => 'Written Off',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::None => 'لا يوجد',
            self::InProgress => 'قيد التحصيل',
            self::Escalated => 'تم التصعيد',
            self::Legal => 'إجراء قانوني',
            self::WrittenOff => 'تم الإعدام',
        };
    }
}
