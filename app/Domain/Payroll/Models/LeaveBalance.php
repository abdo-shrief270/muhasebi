<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('leave_balances')]
#[Fillable([
    'tenant_id',
    'employee_id',
    'leave_type_id',
    'year',
    'entitled_days',
    'used_days',
    'carried_days',
])]
class LeaveBalance extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled_days' => 'integer',
            'used_days' => 'integer',
            'carried_days' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * Calculate the available leave days.
     */
    public function availableDays(): int
    {
        return ($this->entitled_days + $this->carried_days) - $this->used_days;
    }

    /**
     * Deduct days from the balance.
     */
    public function deductDays(int $days): void
    {
        $this->used_days += $days;
        $this->save();
    }
}
