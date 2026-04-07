<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tax\Enums\TaxReturnStatus;
use App\Domain\Tax\Enums\TaxReturnType;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('tax_returns')]
#[Fillable([
    'tenant_id',
    'fiscal_year_id',
    'return_type',
    'period_from',
    'period_to',
    'status',
    'gross_revenue',
    'total_expenses',
    'adjustments_total',
    'taxable_income',
    'tax_due',
    'tax_paid',
    'balance_due',
    'filed_at',
    'paid_at',
    'filing_reference',
    'notes',
    'data',
    'created_by',
])]
class TaxReturn extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'return_type' => TaxReturnType::class,
            'status' => TaxReturnStatus::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'gross_revenue' => 'decimal:2',
            'total_expenses' => 'decimal:2',
            'adjustments_total' => 'decimal:2',
            'taxable_income' => 'decimal:2',
            'tax_due' => 'decimal:2',
            'tax_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'filed_at' => 'datetime',
            'paid_at' => 'datetime',
            'data' => 'array',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'gross_revenue' => 0,
        'total_expenses' => 0,
        'adjustments_total' => 0,
        'taxable_income' => 0,
        'tax_due' => 0,
        'tax_paid' => 0,
        'balance_due' => 0,
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['return_type', 'status', 'taxable_income', 'tax_due', 'balance_due'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
