<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Enums;

enum DisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::UnderReview => 'Under Review',
            self::Resolved => 'Resolved',
            self::Rejected => 'Rejected',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Open => 'مفتوح',
            self::UnderReview => 'قيد المراجعة',
            self::Resolved => 'تم الحل',
            self::Rejected => 'مرفوض',
        };
    }
}
