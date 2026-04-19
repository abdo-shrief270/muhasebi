<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('bank_statement_lines')]
#[Fillable([
    'reconciliation_id',
    'date',
    'description',
    'reference',
    'amount',
    'type',
    'journal_entry_line_id',
    'status',
    'suggested_account_id',
    'suggested_vendor_id',
    'confidence_score',
    'category_rule_id',
    'is_auto_categorized',
    'auto_matched',
    'match_confidence',
    'matched_invoice_id',
    'matched_bill_id',
    'auto_posted',
    'posted_journal_entry_id',
])]
class BankStatementLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'confidence_score' => 'decimal:2',
            'is_auto_categorized' => 'boolean',
            'auto_matched' => 'boolean',
            'match_confidence' => 'decimal:2',
            'auto_posted' => 'boolean',
        ];
    }

    // ── Relationships ──

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    public function journalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class);
    }

    public function suggestedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'suggested_account_id');
    }

    public function suggestedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'suggested_vendor_id');
    }

    public function categoryRule(): BelongsTo
    {
        return $this->belongsTo(BankCategorizationRule::class, 'category_rule_id');
    }

    public function matchedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'matched_invoice_id');
    }

    public function matchedBill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'matched_bill_id');
    }

    public function postedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'posted_journal_entry_id');
    }

    // ── Scopes ──

    public function scopeUnmatched($query)
    {
        return $query->where('status', 'unmatched');
    }

    public function scopeMatched($query)
    {
        return $query->where('status', 'matched');
    }

    // ── Helpers ──

    public function isDeposit(): bool
    {
        return $this->type === 'deposit';
    }

    public function isWithdrawal(): bool
    {
        return $this->type === 'withdrawal';
    }

    public function isMatched(): bool
    {
        return $this->status === 'matched';
    }
}
