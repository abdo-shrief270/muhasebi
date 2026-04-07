<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum MovementType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case ReturnIn = 'return_in';
    case ReturnOut = 'return_out';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Sale => 'Sale',
            self::Adjustment => 'Adjustment',
            self::Transfer => 'Transfer',
            self::ReturnIn => 'Return In',
            self::ReturnOut => 'Return Out',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Purchase => 'شراء',
            self::Sale => 'بيع',
            self::Adjustment => 'تسوية',
            self::Transfer => 'تحويل',
            self::ReturnIn => 'مرتجع وارد',
            self::ReturnOut => 'مرتجع صادر',
        };
    }

    /** Whether this movement type increases stock. */
    public function increasesStock(): bool
    {
        return match ($this) {
            self::Purchase, self::ReturnIn, self::Transfer => true,
            self::Sale, self::ReturnOut => false,
            self::Adjustment => true, // sign is determined by quantity
        };
    }
}
