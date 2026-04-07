<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

<<<<<<< HEAD
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
=======
use App\Domain\Accounting\Enums\CostCenterType;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
>>>>>>> feat/cc-4
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
<<<<<<< HEAD
=======
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
>>>>>>> feat/cc-4

#[Table('cost_centers')]
#[Fillable([
    'tenant_id',
<<<<<<< HEAD
=======
    'parent_id',
>>>>>>> feat/cc-4
    'code',
    'name_ar',
    'name_en',
    'type',
<<<<<<< HEAD
    'parent_id',
    'manager_id',
    'budget_amount',
    'is_active',
    'notes',
=======
    'is_active',
    'budget',
    'description',
>>>>>>> feat/cc-4
])]
class CostCenter extends Model
{
    use BelongsToTenant;
<<<<<<< HEAD
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

=======
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

>>>>>>> feat/cc-4
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
<<<<<<< HEAD
            'is_active' => 'boolean',
            'budget_amount' => 'decimal:2',
        ];
    }

=======
            'type' => CostCenterType::class,
            'is_active' => 'boolean',
            'budget' => 'decimal:2',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'budget' => 0,
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): CostCenterFactory
    {
        return CostCenterFactory::new();
    }

>>>>>>> feat/cc-4
    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

<<<<<<< HEAD
=======
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

>>>>>>> feat/cc-4
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

<<<<<<< HEAD
    public function manager(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'manager_id');
=======
    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'cost_center_id');
>>>>>>> feat/cc-4
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

<<<<<<< HEAD
=======
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

>>>>>>> feat/cc-4
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
<<<<<<< HEAD
            $q->where('code', 'ilike', "%{$term}%")
                ->orWhere('name_ar', 'ilike', "%{$term}%")
                ->orWhere('name_en', 'ilike', "%{$term}%");
        });
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        if (! $type) {
            return $query;
        }

        return $query->where('type', $type);
=======
            $q->where('name_ar', 'ilike', "%{$term}%")
                ->orWhere('name_en', 'ilike', "%{$term}%")
                ->orWhere('code', 'ilike', "%{$term}%");
        });
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    /**
     * Build a breadcrumb path from root to this node.
     *
     * @return string e.g. "HQ > Finance > Payroll"
     */
    public function fullPath(): string
    {
        $segments = collect([$this->name_en]);
        $current = $this;

        while ($current->parent_id && $current = $current->parent) {
            $segments->prepend($current->name_en);
        }

        return $segments->implode(' > ');
    }

    /**
     * Check if setting the given parent would create a circular reference.
     */
    public function wouldCreateCircle(int $parentId): bool
    {
        if ($parentId === $this->id) {
            return true;
        }

        $ancestor = self::find($parentId);

        while ($ancestor && $ancestor->parent_id) {
            if ($ancestor->parent_id === $this->id) {
                return true;
            }
            $ancestor = self::find($ancestor->parent_id);
        }

        return false;
    }

    /**
     * Calculate profit & loss for this cost center.
     *
     * @return array{revenue: string, expenses: string, net_profit: string}
     */
    public function profitAndLoss(): array
    {
        $lines = $this->journalEntryLines()->with('account')->get();

        $revenue = '0';
        $expenses = '0';

        foreach ($lines as $line) {
            if ($line->account && $line->account->type === \App\Domain\Accounting\Enums\AccountType::Revenue) {
                $revenue = bcadd($revenue, bcsub((string) $line->credit, (string) $line->debit, 2), 2);
            }
            if ($line->account && $line->account->type === \App\Domain\Accounting\Enums\AccountType::Expense) {
                $expenses = bcadd($expenses, bcsub((string) $line->debit, (string) $line->credit, 2), 2);
            }
        }

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_profit' => bcsub($revenue, $expenses, 2),
        ];
    }

    /**
     * Calculate cost analysis: budget vs actual spending.
     *
     * @return array{budget: string, actual: string, variance: string, utilization: string}
     */
    public function costAnalysis(): array
    {
        $lines = $this->journalEntryLines()->with('account')->get();

        $actual = '0';

        foreach ($lines as $line) {
            if ($line->account && $line->account->type === \App\Domain\Accounting\Enums\AccountType::Expense) {
                $actual = bcadd($actual, bcsub((string) $line->debit, (string) $line->credit, 2), 2);
            }
        }

        $budget = (string) ($this->budget ?? '0');
        $variance = bcsub($budget, $actual, 2);
        $utilization = bccomp($budget, '0', 2) > 0
            ? bcmul(bcdiv($actual, $budget, 4), '100', 2)
            : '0.00';

        return [
            'budget' => $budget,
            'actual' => $actual,
            'variance' => $variance,
            'utilization' => $utilization,
        ];
    }

    /**
     * Whether this cost center has journal entries (prevent deletion).
     */
    public function hasJournalEntries(): bool
    {
        return $this->journalEntryLines()->exists();
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name_ar', 'name_en', 'type', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
>>>>>>> feat/cc-4
    }
}
