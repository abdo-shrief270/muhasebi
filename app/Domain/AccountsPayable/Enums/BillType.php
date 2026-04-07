<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Enums;

enum BillType: string
{
    case Bill = 'bill';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    public function label(): string
    {
        return match ($this) {
            self::Bill => 'Bill',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Bill => 'فاتورة مشتريات',
            self::CreditNote => 'إشعار دائن',
            self::DebitNote => 'إشعار مدين',
        };
    }
}
