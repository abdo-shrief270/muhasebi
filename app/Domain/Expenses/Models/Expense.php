<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Expenses\Enums\ExpensePaymentMethod;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('expenses')]
#[Fillable([
    'tenant_id',
    'category_id',
    'expense_report_id',
    'user_id',
    'vendor_id',
    'journal_entry_id',
    'approved_by',
    'created_by',
    'status',
    'payment_method',
    'amount',
    'vat_amount',
    'vat_rate',
    'date',
    'description',
    'reference',
    'notes',
    'approved_at',
    'reimbursed_at',
])]
class Expense extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'amount' => 0,
        'vat_amount' => 0,
        'vat_rate' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'payment_method' => ExpensePaymentMethod::class,
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'date' => 'date',
            'approved_at' => 'datetime',
            'reimbursed_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function expenseReport(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === ExpenseStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === ExpenseStatus::Approved;
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeByStatus(Builder $query, ExpenseStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeByUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('date', '>=', $from);
        }

        if ($to) {
            $query->where('date', '<=', $to);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('description', 'ilike', "%{$term}%")
                ->orWhere('reference', 'ilike', "%{$term}%")
                ->orWhere('notes', 'ilike', "%{$term}%");
        });
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'vat_amount', 'date', 'description'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
