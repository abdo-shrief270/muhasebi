<?php

declare(strict_types=1);

namespace App\Domain\CostCenter\Enums;

enum CostCenterType: string
{
    case Department = 'department';
    case Project = 'project';
    case Client = 'client';
    case Branch = 'branch';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'Department',
            self::Project => 'Project',
            self::Client => 'Client',
            self::Branch => 'Branch',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Department => 'قسم',
            self::Project => 'مشروع',
            self::Client => 'عميل',
            self::Branch => 'فرع',
        };
    }
}
