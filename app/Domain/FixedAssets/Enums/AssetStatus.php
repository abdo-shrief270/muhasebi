<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case Disposed = 'disposed';
    case Retired = 'retired';
    case UnderMaintenance = 'under_maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disposed => 'Disposed',
            self::Retired => 'Retired',
            self::UnderMaintenance => 'Under Maintenance',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Disposed => 'تم التصرف فيه',
            self::Retired => 'متقاعد',
            self::UnderMaintenance => 'تحت الصيانة',
        };
    }

    public function canDepreciate(): bool
    {
        return $this === self::Active;
    }

    public function canDispose(): bool
    {
        return in_array($this, [self::Active, self::UnderMaintenance], true);
    }
}
