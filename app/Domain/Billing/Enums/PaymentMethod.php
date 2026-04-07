<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Check = 'check';
    case CreditCard = 'credit_card';
    case MobileWallet = 'mobile_wallet';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::Check => 'Check',
            self::CreditCard => 'Credit Card',
            self::MobileWallet => 'Mobile Wallet',
            self::Other => 'Other',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::BankTransfer => 'تحويل بنكي',
            self::Check => 'شيك',
            self::CreditCard => 'بطاقة ائتمان',
            self::MobileWallet => 'محفظة إلكترونية',
            self::Other => 'أخرى',
        };
    }
}
