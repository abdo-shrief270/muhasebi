<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Investor\Enums\DistributionStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use Database\Factories\ProfitDistributionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('profit_distributions')]
#[Fillable([
    'investor_id',
    'tenant_id',
    'month',
    'year',
    'tenant_revenue',
    'tenant_expenses',
    'net_profit',
    'ownership_percentage',
    'investor_share',
    'status',
    'paid_at',
    'notes',
])]
class ProfitDistribution extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'tenant_revenue' => 0,
        'tenant_expenses' => 0,
        'net_profit' => 0,
        'investor_share' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DistributionStatus::class,
            'tenant_revenue' => 'decimal:2',
            'tenant_expenses' => 'decimal:2',
            'net_profit' => 'decimal:2',
            'ownership_percentage' => 'decimal:2',
            'investor_share' => 'decimal:2',
            'paid_at' => 'datetime',
            'month' => 'integer',
            'year' => 'integer',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForPeriod(Builder $query, int $month, int $year): Builder
    {
        return $query->where('month', $month)->where('year', $year);
    }

    public function scopeOfStatus(Builder $query, DistributionStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForInvestor(Builder $query, int $investorId): Builder
    {
        return $query->where('investor_id', $investorId);
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'investor_share', 'net_profit'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function newFactory(): ProfitDistributionFactory
    {
        return ProfitDistributionFactory::new();
    }
}
