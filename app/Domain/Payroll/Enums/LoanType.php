<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

enum LoanType: string
{
    case Personal = 'personal';
    case Emergency = 'emergency';
    case Housing = 'housing';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personal',
            self::Emergency => 'Emergency',
            self::Housing => 'Housing',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Personal => 'شخصي',
            self::Emergency => 'طوارئ',
            self::Housing => 'سكن',
        };
    }
}
