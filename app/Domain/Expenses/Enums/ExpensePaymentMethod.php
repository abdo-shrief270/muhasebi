<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Enums;

enum ExpensePaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case CompanyCard = 'company_card';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::CompanyCard => 'Company Card',
            self::Personal => 'Personal',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::BankTransfer => 'تحويل بنكي',
            self::CompanyCard => 'بطاقة الشركة',
            self::Personal => 'شخصي',
        };
    }
}
