<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\CalculationType;
use App\Domain\Payroll\Enums\SalaryComponentType;
use App\Domain\Shared\Traits\BelongsToTenant;
use Database\Factories\SalaryComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('salary_components')]
#[Fillable([
    'tenant_id',
    'name_ar',
    'name_en',
    'code',
    'type',
    'calculation_type',
    'default_amount',
    'is_taxable',
    'is_active',
])]
class SalaryComponent extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SalaryComponentType::class,
            'calculation_type' => CalculationType::class,
            'default_amount' => 'decimal:2',
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): SalaryComponentFactory
    {
        return SalaryComponentFactory::new();
    }
}
