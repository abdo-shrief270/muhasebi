<?php

declare(strict_types=1);

namespace App\Domain\ECommerce\Enums;

enum OrderSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Synced => 'Synced',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
