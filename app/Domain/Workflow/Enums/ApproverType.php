<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Enums;

enum ApproverType: string
{
    case User = 'user';
    case Role = 'role';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Role => 'Role',
            self::Manager => 'Manager',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::User => 'مستخدم',
            self::Role => 'دور',
            self::Manager => 'مدير',
        };
    }
}
