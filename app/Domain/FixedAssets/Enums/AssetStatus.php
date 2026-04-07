<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case FullyDepreciated = 'fully_depreciated';
    case Disposed = 'disposed';
    case Draft = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::FullyDepreciated => 'Fully Depreciated',
            self::Disposed => 'Disposed',
            self::Draft => 'Draft',
        };
    }
}
