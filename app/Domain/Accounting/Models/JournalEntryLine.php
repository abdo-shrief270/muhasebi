<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use Database\Factories\JournalEntryLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('journal_entry_lines')]
#[Fillable([
    'journal_entry_id',
    'account_id',
    'debit',
    'credit',
    'currency',
    'description',
    'cost_center',
    'cost_center_id',
])]
class JournalEntryLine extends Model
{
    use BelongsToTenant, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'debit' => 0,
        'credit' => 0,
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): JournalEntryLineFactory
    {
        return JournalEntryLineFactory::new();
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function isDebit(): bool
    {
        return bccomp((string) $this->debit, '0', 2) > 0;
    }

    public function isCredit(): bool
    {
        return bccomp((string) $this->credit, '0', 2) > 0;
    }

    public function amount(): float
    {
        return $this->isDebit() ? (float) $this->debit : (float) $this->credit;
    }
}
