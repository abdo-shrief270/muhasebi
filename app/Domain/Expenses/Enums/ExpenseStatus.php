<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Enums;

enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reimbursed = 'reimbursed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Reimbursed => 'Reimbursed',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Submitted => 'مقدمة',
            self::Approved => 'معتمدة',
            self::Rejected => 'مرفوضة',
            self::Reimbursed => 'مسددة',
        };
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function canApprove(): bool
    {
        return $this === self::Submitted;
    }

    public function canReject(): bool
    {
        return $this === self::Submitted;
    }

    public function canReimburse(): bool
    {
        return $this === self::Approved;
    }

    public function canEdit(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'blue',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Reimbursed => 'purple',
        };
    }
}
