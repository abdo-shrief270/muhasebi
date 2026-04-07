<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\CalculationType;
use App\Domain\Payroll\Enums\SalaryComponentType;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('employee_salary_details')]
#[Fillable([
    'tenant_id',
    'employee_id',
    'salary_component_id',
    'type',
    'calculation_type',
    'amount',
    'percentage',
])]
class EmployeeSalaryDetail extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SalaryComponentType::class,
            'calculation_type' => CalculationType::class,
            'amount' => 'decimal:2',
            'percentage' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function salaryComponent(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class);
    }

    /**
     * Calculate the effective amount based on calculation type.
     *
     * @param  string|null  $basicSalary  The employee's basic salary
     * @param  string|null  $grossSalary  The employee's gross salary
     */
    public function effectiveAmount(?string $basicSalary = null, ?string $grossSalary = null): string
    {
        return match ($this->calculation_type) {
            CalculationType::Fixed => number_format((float) $this->amount, 2, '.', ''),
            CalculationType::PercentageOfBasic => bcmul(
                $basicSalary ?? '0',
                bcdiv((string) $this->percentage, '100', 4),
                2
            ),
            CalculationType::PercentageOfGross => bcmul(
                $grossSalary ?? '0',
                bcdiv((string) $this->percentage, '100', 4),
                2
            ),
        };
    }
}
