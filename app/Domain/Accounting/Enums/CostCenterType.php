<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

enum CostCenterType: string
{
    case Department = 'department';
    case Project = 'project';
    case Branch = 'branch';
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'Department',
            self::Project => 'Project',
            self::Branch => 'Branch',
            self::Product => 'Product',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Department => 'قسم',
            self::Project => 'مشروع',
            self::Branch => 'فرع',
            self::Product => 'منتج',
        };
    }
}
