<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Inventory\Enums\ValuationMethod;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('products')]
#[Fillable([
    'tenant_id',
    'category_id',
    'sku',
    'name_ar',
    'name_en',
    'description',
    'unit_of_measure',
    'purchase_price',
    'selling_price',
    'vat_rate',
    'reorder_level',
    'current_stock',
    'valuation_method',
    'is_active',
    'account_id',
    'cogs_account_id',
    'revenue_account_id',
])]
class Product extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'reorder_level' => 'integer',
            'current_stock' => 'integer',
            'valuation_method' => ValuationMethod::class,
            'is_active' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'unit_of_measure' => 'unit',
        'purchase_price' => 0,
        'selling_price' => 0,
        'vat_rate' => 14,
        'reorder_level' => 0,
        'current_stock' => 0,
        'valuation_method' => 'weighted_average',
        'is_active' => true,
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name_ar', 'ilike', "%{$term}%")
                ->orWhere('name_en', 'ilike', "%{$term}%")
                ->orWhere('sku', 'ilike', "%{$term}%");
        });
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('current_stock', '<=', 'reorder_level');
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
            ->logOnly(['sku', 'name_ar', 'name_en', 'purchase_price', 'selling_price', 'current_stock', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
