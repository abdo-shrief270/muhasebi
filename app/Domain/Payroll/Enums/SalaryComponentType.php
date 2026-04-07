<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

enum SalaryComponentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Earning',
            self::Deduction => 'Deduction',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Earning => 'استحقاق',
            self::Deduction => 'استقطاع',
        };
    }
}
