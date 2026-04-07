<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Enums;

enum ItemCodeSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Synced => 'Synced',
            self::Error => 'Error',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Synced => 'تمت المزامنة',
            self::Error => 'خطأ',
        };
    }
}
