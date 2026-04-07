<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        };
    }
}
