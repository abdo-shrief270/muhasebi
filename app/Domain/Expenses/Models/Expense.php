<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('expenses')]
#[Fillable([
    'tenant_id',
    'user_id',
    'category_id',
    'expense_report_id',
    'description',
    'amount',
    'vat_rate',
    'vat_amount',
    'total',
    'currency',
    'date',
    'receipt_path',
    'status',
    'notes',
    'approved_by',
    'approved_at',
    'reimbursed_at',
    'journal_entry_id',
])]
class Expense extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => ExpenseStatus::class,
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
            'reimbursed_at' => 'datetime',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'amount' => 0,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 0,
        'currency' => 'EGP',
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function expenseReport(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
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

    public function scopeForCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('description', 'ilike', "%{$term}%")
                ->orWhere('notes', 'ilike', "%{$term}%");
        });
    }
}
