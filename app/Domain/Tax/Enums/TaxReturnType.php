<?php

declare(strict_types=1);

namespace App\Domain\Tax\Enums;

enum TaxReturnType: string
{
    case VatMonthly = 'vat_monthly';
    case VatQuarterly = 'vat_quarterly';
    case CorporateTax = 'corporate_tax';

    public function label(): string
    {
        return match ($this) {
            self::VatMonthly => 'VAT Monthly',
            self::VatQuarterly => 'VAT Quarterly',
            self::CorporateTax => 'Corporate Tax',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::VatMonthly => 'ضريبة القيمة المضافة - شهري',
            self::VatQuarterly => 'ضريبة القيمة المضافة - ربع سنوي',
            self::CorporateTax => 'ضريبة الشركات',
        };
    }
}
