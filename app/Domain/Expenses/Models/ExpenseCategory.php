<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('expense_categories')]
#[Fillable([
    'tenant_id',
    'name',
    'name_ar',
    'description',
    'gl_account_id',
    'is_active',
])]
class ExpenseCategory extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
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
            $q->where('name', 'ilike', "%{$term}%")
                ->orWhere('name_ar', 'ilike', "%{$term}%");
        });
    }
}
