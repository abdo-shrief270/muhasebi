<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\LoanStatus;
use App\Domain\Payroll\Enums\LoanType;
use App\Domain\Shared\Traits\BelongsToTenant;
use Database\Factories\EmployeeLoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('employee_loans')]
#[Fillable([
    'tenant_id',
    'employee_id',
    'loan_type',
    'amount',
    'installment_amount',
    'remaining_balance',
    'start_date',
    'status',
])]
class EmployeeLoan extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'loan_type' => LoanType::class,
            'amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'remaining_balance' => 'decimal:2',
            'start_date' => 'date',
            'status' => LoanStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Record an installment payment.
     */
    public function recordInstallment(): void
    {
        $newBalance = bcsub((string) $this->remaining_balance, (string) $this->installment_amount, 2);
        $this->remaining_balance = $newBalance;

        if (bccomp($newBalance, '0.00', 2) <= 0) {
            $this->remaining_balance = '0.00';
            $this->status = LoanStatus::Completed;
        }

        $this->save();
    }

    protected static function newFactory(): EmployeeLoanFactory
    {
        return EmployeeLoanFactory::new();
    }
}
