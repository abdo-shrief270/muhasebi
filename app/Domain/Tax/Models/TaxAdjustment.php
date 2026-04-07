<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tax\Enums\TaxAdjustmentType;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('tax_adjustments')]
#[Fillable([
    'tenant_id',
    'fiscal_year_id',
    'adjustment_type',
    'description_ar',
    'description_en',
    'amount',
    'is_addition',
    'journal_entry_id',
    'created_by',
])]
class TaxAdjustment extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'adjustment_type' => TaxAdjustmentType::class,
            'amount' => 'decimal:2',
            'is_addition' => 'boolean',
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
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
            ->logOnly(['adjustment_type', 'amount', 'is_addition', 'description_ar'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
