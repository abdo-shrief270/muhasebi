<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use Database\Factories\InvestorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('investors')]
#[Fillable([
    'name',
    'email',
    'phone',
    'join_date',
    'is_active',
    'notes',
])]
class Investor extends Model
{
    use HasFactory;
    use SoftDeletes;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenantShares(): HasMany
    {
        return $this->hasMany(InvestorTenantShare::class);
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(ProfitDistribution::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function newFactory(): InvestorFactory
    {
        return InvestorFactory::new();
    }
}
