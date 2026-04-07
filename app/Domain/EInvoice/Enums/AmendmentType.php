<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Enums;

enum AmendmentType: string
{
    case Cancellation = 'cancellation';
    case Amendment = 'amendment';

    public function label(): string
    {
        return match ($this) {
            self::Cancellation => 'Cancellation',
            self::Amendment => 'Amendment',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Cancellation => 'إلغاء',
            self::Amendment => 'تعديل',
        };
    }
}
