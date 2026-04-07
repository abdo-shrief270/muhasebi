<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Check = 'check';
    case MobileWallet = 'mobile_wallet';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::Check => 'Check',
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
            self::MobileWallet => 'محفظة إلكترونية',
            self::Other => 'أخرى',
        };
    }

    public function defaultAccountCode(): string
    {
        return match ($this) {
            self::Cash, self::Other => config('accounting.default_accounts.cash'),
            self::BankTransfer, self::Check, self::MobileWallet => config('accounting.default_accounts.bank'),
        };
    }
}
