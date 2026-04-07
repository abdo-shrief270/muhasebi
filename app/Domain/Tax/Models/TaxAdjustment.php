<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tax\Enums\TaxAdjustmentType;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('tax_adjustments')]
#[Fillable([
    'tenant_id',
    'fiscal_year_id',
    'type',
    'description_ar',
    'description_en',
    'amount',
    'reference',
])]
class TaxAdjustment extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaxAdjustmentType::class,
            'amount' => 'decimal:2',
        ];
    }

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

    public function scopeOfType(Builder $query, TaxAdjustmentType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeForFiscalYear(Builder $query, int $fiscalYearId): Builder
    {
        return $query->where('fiscal_year_id', $fiscalYearId);
    }
}
