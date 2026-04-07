<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Enums;

use App\Domain\Billing\Enums\InvoiceType;

enum EtaDocumentType: string
{
    case Invoice = 'I';
    case CreditNote = 'C';
    case DebitNote = 'D';

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

    public static function fromInvoiceType(InvoiceType $type): self
    {
        return match ($type) {
            InvoiceType::Invoice => self::Invoice,
            InvoiceType::CreditNote => self::CreditNote,
            InvoiceType::DebitNote => self::DebitNote,
        };
    }
}
