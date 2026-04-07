<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Tax\Enums\TaxReturnStatus;
use App\Domain\Tax\Enums\TaxReturnType;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\TaxReturnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('tax_returns')]
#[Fillable([
    'tenant_id',
    'fiscal_year_id',
    'type',
    'status',
    'period_from',
    'period_to',
    'revenue',
    'expenses',
    'taxable_income',
    'tax_amount',
    'output_vat',
    'input_vat',
    'net_vat',
    'filed_at',
    'paid_at',
    'payment_reference',
    'notes',
])]
class TaxReturn extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaxReturnType::class,
            'status' => TaxReturnStatus::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'revenue' => 'decimal:2',
            'expenses' => 'decimal:2',
            'taxable_income' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'output_vat' => 'decimal:2',
            'input_vat' => 'decimal:2',
            'net_vat' => 'decimal:2',
            'filed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'revenue' => 0,
        'expenses' => 0,
        'taxable_income' => 0,
        'tax_amount' => 0,
        'output_vat' => 0,
        'input_vat' => 0,
        'net_vat' => 0,
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): TaxReturnFactory
    {
        return TaxReturnFactory::new();
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

    public function adjustments(): HasMany
    {
        return $this->hasMany(TaxAdjustment::class);
    }
}
