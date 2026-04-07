<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Enums;

enum AmendmentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Submitted => 'تم الإرسال',
            self::Accepted => 'مقبول',
            self::Rejected => 'مرفوض',
        };
    }

    public function canSubmit(): bool
    {
        return $this === self::Pending;
    }

    public function canCancel(): bool
    {
        return $this === self::Pending;
    }
}
