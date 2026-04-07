<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('bank_reconciliations')]
#[Fillable([
    'tenant_id',
    'account_id',
    'statement_date',
    'statement_balance',
    'book_balance',
    'adjusted_book_balance',
    'status',
    'reconciled_at',
    'reconciled_by',
    'notes',
])]
class BankReconciliation extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_balance' => 'decimal:2',
            'book_balance' => 'decimal:2',
            'adjusted_book_balance' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'statement_balance', 'book_balance', 'statement_date'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Bank reconciliation {$eventName}");
    }

    // ── Relationships ──

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function reconciledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'reconciliation_id');
    }

    // ── Scopes ──

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    // ── Helpers ──

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function unmatchedCount(): int
    {
        return $this->statementLines()->where('status', 'unmatched')->count();
    }

    public function difference(): string
    {
        return bcsub((string) $this->statement_balance, (string) $this->adjusted_book_balance, 2);
    }
}
