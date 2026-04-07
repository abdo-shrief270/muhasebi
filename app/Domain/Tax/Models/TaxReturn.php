<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tax\Enums\TaxReturnStatus;
use App\Domain\Tax\Enums\TaxReturnType;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('tax_returns')]
#[Fillable([
    'tenant_id',
    'fiscal_year_id',
    'type',
    'period_from',
    'period_to',
    'status',
    'tax_due',
    'tax_paid',
    'balance',
    'filed_at',
    'data',
])]
class TaxReturn extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaxReturnType::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'status' => TaxReturnStatus::class,
            'tax_due' => 'decimal:2',
            'tax_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'filed_at' => 'datetime',
            'data' => 'array',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'tax_due' => 0,
        'tax_paid' => 0,
        'balance' => 0,
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

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOfType(Builder $query, TaxReturnType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeOfStatus(Builder $query, TaxReturnStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForFiscalYear(Builder $query, int $fiscalYearId): Builder
    {
        return $query->where('fiscal_year_id', $fiscalYearId);
    }
}
