<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

enum ReconciliationStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
