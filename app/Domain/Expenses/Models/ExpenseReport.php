<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('expense_reports')]
#[Fillable([
    'tenant_id',
    'user_id',
    'title',
    'description',
    'status',
    'total_amount',
    'total_vat',
    'grand_total',
    'currency',
    'approved_by',
    'approved_at',
    'notes',
])]
class ExpenseReport extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'total_amount' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'total_amount' => 0,
        'total_vat' => 0,
        'grand_total' => 0,
        'currency' => 'EGP',
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOfStatus(Builder $query, ExpenseStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('title', 'ilike', "%{$term}%")
                ->orWhere('description', 'ilike', "%{$term}%");
        });
    }
}
