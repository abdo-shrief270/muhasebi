<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Models;

use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\FixedAssets\Enums\AssetStatus;
use App\Domain\FixedAssets\Enums\DepreciationMethod;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('fixed_assets')]
#[Fillable([
    'tenant_id',
    'category_id',
    'vendor_id',
    'name_ar',
    'name_en',
    'code',
    'description',
    'serial_number',
    'location',
    'status',
    'depreciation_method',
    'acquisition_date',
    'depreciation_start_date',
    'last_depreciation_date',
    'acquisition_cost',
    'salvage_value',
    'useful_life_years',
    'accumulated_depreciation',
    'book_value',
    'acquisition_journal_id',
    'responsible_user_id',
    'created_by',
    'notes',
])]
class FixedAsset extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'depreciation_method' => DepreciationMethod::class,
            'acquisition_date' => 'date',
            'depreciation_start_date' => 'date',
            'last_depreciation_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'useful_life_years' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'book_value' => 'decimal:2',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(AssetDisposal::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acquisitionJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'acquisition_journal_id');
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function remainingLife(): string
    {
        return bcsub((string) $this->useful_life_years, (string) $this->accumulated_depreciation !== '0.00'
            ? bcdiv((string) $this->accumulated_depreciation, bcdiv(bcsub((string) $this->acquisition_cost, (string) $this->salvage_value, 2), (string) $this->useful_life_years, 10), 2)
            : '0.00', 2);
    }

    public function monthlyDepreciation(): string
    {
        if (bccomp((string) $this->useful_life_years, '0', 2) === 0) {
            return '0.00';
        }

        return match ($this->depreciation_method) {
            DepreciationMethod::StraightLine => bcdiv(
                bcsub((string) $this->acquisition_cost, (string) $this->salvage_value, 2),
                bcmul((string) $this->useful_life_years, '12', 2),
                2,
            ),
            DepreciationMethod::DecliningBalance => bcdiv(
                bcmul((string) $this->book_value, '2', 2),
                bcmul((string) $this->useful_life_years, '12', 2),
                2,
            ),
        };
    }

    public function isFullyDepreciated(): bool
    {
        return bccomp((string) $this->book_value, (string) $this->salvage_value, 2) <= 0;
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AssetStatus::Active);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name_ar', 'ilike', "%{$term}%")
                ->orWhere('name_en', 'ilike', "%{$term}%")
                ->orWhere('code', 'ilike', "%{$term}%")
                ->orWhere('serial_number', 'ilike', "%{$term}%");
        });
    }

    public function scopeByCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name_ar', 'name_en', 'code', 'status', 'acquisition_cost', 'book_value'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
