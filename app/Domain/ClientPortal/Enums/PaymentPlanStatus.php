<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Enums;

enum PaymentPlanStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Defaulted = 'defaulted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Defaulted => 'Defaulted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Completed => 'مكتمل',
            self::Defaulted => 'متعثر',
            self::Cancelled => 'ملغى',
        };
    }
}
