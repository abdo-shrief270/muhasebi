<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Enums;

enum EtaAmendmentType: string
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

    /**
     * ETA allows 3 days for cancellations, 3 days for amendments.
     */
    public function deadlineDays(): int
    {
        return match ($this) {
            self::Cancellation => 3,
            self::Amendment => 3,
        };
    }
}
