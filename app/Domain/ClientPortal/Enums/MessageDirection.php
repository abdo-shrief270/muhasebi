<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Enums;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Inbound',
            self::Outbound => 'Outbound',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Inbound => 'وارد',
            self::Outbound => 'صادر',
        };
    }
}
