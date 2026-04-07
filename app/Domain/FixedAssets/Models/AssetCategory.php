<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\FixedAssets\Enums\DepreciationMethod;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('asset_categories')]
#[Fillable([
    'tenant_id',
    'name_ar',
    'name_en',
    'code',
    'depreciation_method',
    'default_useful_life_years',
    'default_salvage_percent',
    'asset_account_id',
    'depreciation_expense_account_id',
    'accumulated_depreciation_account_id',
    'disposal_account_id',
    'notes',
])]
class AssetCategory extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'depreciation_method' => DepreciationMethod::class,
            'default_useful_life_years' => 'decimal:2',
            'default_salvage_percent' => 'decimal:2',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function disposalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_account_id');
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name_ar', 'name_en', 'code', 'depreciation_method', 'default_useful_life_years', 'default_salvage_percent'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
