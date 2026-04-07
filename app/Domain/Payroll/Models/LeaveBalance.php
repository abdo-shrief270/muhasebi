<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
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
    'entitled',
    'carried_forward',
    'used',
])]
class LeaveBalance extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled' => 'decimal:2',
            'carried_forward' => 'decimal:2',
            'used' => 'decimal:2',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'carried_forward' => '0.00',
        'used' => '0.00',
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    /**
     * Calculate available leave days: entitled + carried_forward - used.
     */
    public function availableDays(): string
    {
        return bcsub(
            bcadd((string) $this->entitled, (string) $this->carried_forward, 2),
            (string) $this->used,
            2,
        );
    }
}
