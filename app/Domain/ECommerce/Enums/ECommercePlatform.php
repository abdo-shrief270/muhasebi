<?php

declare(strict_types=1);

namespace App\Domain\ECommerce\Enums;

enum ECommercePlatform: string
{
    case Shopify = 'shopify';
    case WooCommerce = 'woocommerce';
    case Salla = 'salla';
    case Zid = 'zid';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Shopify => 'Shopify',
            self::WooCommerce => 'WooCommerce',
            self::Salla => 'Salla',
            self::Zid => 'Zid',
            self::Custom => 'Custom',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Shopify => 'شوبيفاي',
            self::WooCommerce => 'ووكومرس',
            self::Salla => 'سلة',
            self::Zid => 'زد',
            self::Custom => 'مخصص',
        };
    }
}
