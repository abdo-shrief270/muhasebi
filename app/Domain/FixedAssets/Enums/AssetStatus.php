<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case FullyDepreciated = 'fully_depreciated';
    case Disposed = 'disposed';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::FullyDepreciated => 'Fully Depreciated',
            self::Disposed => 'Disposed',
            self::Inactive => 'Inactive',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::FullyDepreciated => 'مستهلك بالكامل',
            self::Disposed => 'تم التصرف فيه',
            self::Inactive => 'غير نشط',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::FullyDepreciated => 'yellow',
            self::Disposed => 'red',
            self::Inactive => 'gray',
        };
    }
}
