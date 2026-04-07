<?php

declare(strict_types=1);

namespace App\Domain\CostCenter\Models;

use App\Domain\CostCenter\Enums\CostCenterType;
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

#[Table('cost_centers')]
#[Fillable([
    'tenant_id',
    'parent_id',
    'code',
    'name_ar',
    'name_en',
    'type',
    'manager_id',
    'budget_amount',
    'is_active',
    'notes',
])]
class CostCenter extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'department',
        'budget_amount' => 0,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CostCenterType::class,
            'budget_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
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
                ->orWhere('code', 'ilike', "%{$term}%");
        });
    }

    public function scopeByType(Builder $query, CostCenterType $type): Builder
    {
        return $query->where('type', $type);
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Returns a breadcrumb string like "Parent > Child".
     */
    public function fullPath(): string
    {
        $segments = [];
        $current = $this;

        while ($current !== null) {
            array_unshift($segments, $current->name_ar);
            $current = $current->relationLoaded('parent') ? $current->parent : $current->parent()->first();
        }

        return implode(' > ', $segments);
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name_ar', 'name_en', 'type', 'is_active', 'budget_amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
