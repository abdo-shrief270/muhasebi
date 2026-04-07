<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('expense_reports')]
#[Fillable([
    'tenant_id',
    'user_id',
    'approved_by',
    'journal_entry_id',
    'title',
    'status',
    'total_amount',
    'total_vat',
    'period_from',
    'period_to',
    'notes',
    'approved_at',
    'submitted_at',
])]
class ExpenseReport extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'total_amount' => 0,
        'total_vat' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'total_amount' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'period_from' => 'date',
            'period_to' => 'date',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
    // Helper Methods
    // ──────────────────────────────────────

    /**
     * Recalculate totals from associated expenses using bcmath.
     */
    public function recalculate(): self
    {
        $totalAmount = '0';
        $totalVat = '0';

        $this->expenses()->each(function (Expense $expense) use (&$totalAmount, &$totalVat): void {
            $totalAmount = bcadd($totalAmount, (string) $expense->amount, 2);
            $totalVat = bcadd($totalVat, (string) $expense->vat_amount, 2);
        });

        $this->total_amount = $totalAmount;
        $this->total_vat = $totalVat;
        $this->save();

        return $this;
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status', 'total_amount', 'total_vat'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
