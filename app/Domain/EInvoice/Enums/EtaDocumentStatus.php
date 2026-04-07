<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Enums;

enum EtaDocumentStatus: string
{
    case Prepared = 'prepared';
    case Submitted = 'submitted';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Prepared => 'Prepared',
            self::Submitted => 'Submitted',
            self::Valid => 'Valid',
            self::Invalid => 'Invalid',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Prepared => 'جاهز للإرسال',
            self::Submitted => 'تم الإرسال',
            self::Valid => 'صالح',
            self::Invalid => 'غير صالح',
            self::Rejected => 'مرفوض',
            self::Cancelled => 'ملغى',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Prepared => 'gray',
            self::Submitted => 'blue',
            self::Valid => 'green',
            self::Invalid => 'orange',
            self::Rejected => 'red',
            self::Cancelled => 'red',
        };
    }

    public function canSubmit(): bool
    {
        return $this === self::Prepared;
    }

    public function canCancel(): bool
    {
        return $this === self::Valid;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled], true);
    }
}
