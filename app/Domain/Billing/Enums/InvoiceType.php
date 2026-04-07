<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum InvoiceType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Invoice => 'فاتورة',
            self::CreditNote => 'إشعار دائن',
            self::DebitNote => 'إشعار مدين',
        };
    }
}
