<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

enum BudgetStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
