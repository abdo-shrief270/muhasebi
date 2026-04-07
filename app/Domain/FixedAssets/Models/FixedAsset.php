<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Models;

use App\Domain\FixedAssets\Enums\AssetStatus;
use App\Domain\FixedAssets\Enums\DepreciationMethod;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('fixed_assets')]
#[Fillable([
    'tenant_id',
    'category_id',
    'code',
    'name_ar',
    'name_en',
    'description',
    'acquisition_date',
    'acquisition_cost',
    'salvage_value',
    'useful_life_years',
    'depreciation_method',
    'accumulated_depreciation',
    'book_value',
    'status',
    'last_depreciation_date',
    'disposal_date',
    'disposal_amount',
])]
class FixedAsset extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'book_value' => 'decimal:2',
            'useful_life_years' => 'integer',
            'depreciation_method' => DepreciationMethod::class,
            'status' => AssetStatus::class,
            'last_depreciation_date' => 'date',
            'disposal_date' => 'date',
            'disposal_amount' => 'decimal:2',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'accumulated_depreciation' => 0,
        'salvage_value' => 0,
        'status' => 'active',
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AssetStatus::Active);
    }

    public function scopeDepreciable(Builder $query): Builder
    {
        return $query->active()->where('book_value', '>', 0);
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function isFullyDepreciated(): bool
    {
        return bccomp(
            (string) $this->book_value,
            (string) $this->salvage_value,
            2
        ) <= 0;
    }
}
