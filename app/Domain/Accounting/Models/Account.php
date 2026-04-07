<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\AccountFactory;
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

#[Table('accounts')]
#[Fillable([
    'tenant_id',
    'parent_id',
    'code',
    'name_ar',
    'name_en',
    'type',
    'normal_balance',
    'is_active',
    'is_group',
    'level',
    'description',
    'currency',
])]
class Account extends Model
{
    use HasFactory;
    use SoftDeletes;
    use BelongsToTenant;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_active' => 'boolean',
            'is_group' => 'boolean',
            'level' => 'integer',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'is_group' => false,
        'level' => 1,
        'currency' => 'EGP',
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, AccountType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeLeafAccounts(Builder $query): Builder
    {
        return $query->where('is_group', false);
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

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
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
    }
}
