<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Enums;

enum BillType: string
{
    case Bill = 'bill';
    case DebitNote = 'debit_note';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Bill => 'Bill',
            self::DebitNote => 'Debit Note',
            self::CreditNote => 'Credit Note',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Bill => 'فاتورة مشتريات',
            self::DebitNote => 'إشعار مدين',
            self::CreditNote => 'إشعار دائن',
        };
    }
}
