<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Tax\Enums\TaxAdjustmentType;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\TaxAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('tax_adjustments')]
#[Fillable([
    'tenant_id',
    'fiscal_year_id',
    'tax_return_id',
    'type',
    'description',
    'amount',
    'is_addition',
])]
class TaxAdjustment extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaxAdjustmentType::class,
            'amount' => 'decimal:2',
            'is_addition' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'amount' => 0,
        'is_addition' => true,
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): TaxAdjustmentFactory
    {
        return TaxAdjustmentFactory::new();
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

    public function taxReturn(): BelongsTo
    {
        return $this->belongsTo(TaxReturn::class);
    }
}
