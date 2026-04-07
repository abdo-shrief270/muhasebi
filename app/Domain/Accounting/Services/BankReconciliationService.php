<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\BankReconciliation;
use App\Domain\Accounting\Models\BankStatementLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    /**
     * List reconciliations for the current tenant.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return BankReconciliation::query()
            ->with('account:id,code,name_ar,name_en')
            ->when(isset($filters['account_id']), fn ($q) => $q->forAccount((int) $filters['account_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('statement_date')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new bank reconciliation.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): BankReconciliation
    {
        $account = Account::where('tenant_id', (int) app('tenant.id'))
            ->findOrFail($data['account_id']);

        if ($account->is_group) {
            throw ValidationException::withMessages([
                'account_id' => ['Cannot reconcile a group account. Please select a leaf bank account.'],
            ]);
        }

        // Calculate book balance (GL balance at statement date)
        $bookBalance = $this->getBookBalance((int) $account->id, $data['statement_date']);

        return BankReconciliation::create([
            'account_id' => $account->id,
            'statement_date' => $data['statement_date'],
            'statement_balance' => $data['statement_balance'],
            'book_balance' => $bookBalance,
            'adjusted_book_balance' => $bookBalance,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Import bank statement lines from parsed CSV data.
     *
     * @param  array<int, array{date: string, description: string, reference: ?string, amount: float, type: string}>  $lines
     */
    public function importLines(BankReconciliation $reconciliation, array $lines): int
    {
        if ($reconciliation->isCompleted()) {
            throw ValidationException::withMessages([
                'reconciliation' => ['Cannot modify a completed reconciliation.'],
            ]);
        }

        $created = 0;

        foreach ($lines as $line) {
            BankStatementLine::create([
                'reconciliation_id' => $reconciliation->id,
                'date' => $line['date'],
                'description' => $line['description'] ?? null,
                'reference' => $line['reference'] ?? null,
                'amount' => $line['amount'],
                'type' => $line['type'] ?? ((float) $line['amount'] >= 0 ? 'deposit' : 'withdrawal'),
            ]);
            $created++;
        }

        $this->recalculateAdjustedBalance($reconciliation);

        return $created;
    }

    /**
     * Auto-match statement lines to journal entry lines by amount, date, and reference.
     */
    public function autoMatch(BankReconciliation $reconciliation): int
    {
        if ($reconciliation->isCompleted()) {
            throw ValidationException::withMessages([
                'reconciliation' => ['Cannot modify a completed reconciliation.'],
            ]);
        }

        $unmatchedLines = $reconciliation->statementLines()->unmatched()->get();
        $tenantId = (int) app('tenant.id');
        $matched = 0;

        foreach ($unmatchedLines as $stmtLine) {
            // Find GL entries for this bank account matching amount and approximate date
            $glAmount = abs((float) $stmtLine->amount);
            $isDebit = (float) $stmtLine->amount >= 0; // deposit = debit to bank

            $query = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entries.tenant_id', $tenantId)
                ->where('journal_entries.status', JournalEntryStatus::Posted->value)
                ->whereNull('journal_entries.deleted_at')
                ->where('journal_entry_lines.account_id', $reconciliation->account_id);

            if ($isDebit) {
                $query->where('journal_entry_lines.debit', $glAmount)
                    ->where('journal_entry_lines.credit', 0);
            } else {
                $query->where('journal_entry_lines.credit', $glAmount)
                    ->where('journal_entry_lines.debit', 0);
            }

            // Date tolerance: within 3 days
            $query->whereBetween('journal_entries.date', [
                $stmtLine->date->subDays(3)->toDateString(),
                $stmtLine->date->addDays(3)->toDateString(),
            ]);

            // Exclude already matched lines
            $alreadyMatched = BankStatementLine::where('reconciliation_id', $reconciliation->id)
                ->where('status', 'matched')
                ->whereNotNull('journal_entry_line_id')
                ->pluck('journal_entry_line_id')
                ->toArray();

            if (! empty($alreadyMatched)) {
                $query->whereNotIn('journal_entry_lines.id', $alreadyMatched);
            }

            // Reference matching (bonus priority)
            if ($stmtLine->reference) {
                $exactMatch = (clone $query)
                    ->where('journal_entry_lines.description', 'ilike', "%{$stmtLine->reference}%")
                    ->select('journal_entry_lines.id')
                    ->first();

                if ($exactMatch) {
                    $stmtLine->update([
                        'journal_entry_line_id' => $exactMatch->id,
                        'status' => 'matched',
                    ]);
                    $matched++;

                    continue;
                }
            }

            // Amount + date match (take first available)
            $glMatch = $query->select('journal_entry_lines.id')->first();

            if ($glMatch) {
                $stmtLine->update([
                    'journal_entry_line_id' => $glMatch->id,
                    'status' => 'matched',
                ]);
                $matched++;
            }
        }

        $this->recalculateAdjustedBalance($reconciliation);

        return $matched;
    }

    /**
     * Manually match a statement line to a GL entry.
     */
    public function manualMatch(BankStatementLine $line, int $journalEntryLineId): BankStatementLine
    {
        $reconciliation = $line->reconciliation;

        if ($reconciliation->isCompleted()) {
            throw ValidationException::withMessages([
                'reconciliation' => ['Cannot modify a completed reconciliation.'],
            ]);
        }

        $line->update([
            'journal_entry_line_id' => $journalEntryLineId,
            'status' => 'matched',
        ]);

        $this->recalculateAdjustedBalance($reconciliation);

        return $line->refresh();
    }

    /**
     * Unmatch a previously matched statement line.
     */
    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        $reconciliation = $line->reconciliation;

        if ($reconciliation->isCompleted()) {
            throw ValidationException::withMessages([
                'reconciliation' => ['Cannot modify a completed reconciliation.'],
            ]);
        }

        $line->update([
            'journal_entry_line_id' => null,
            'status' => 'unmatched',
        ]);

        $this->recalculateAdjustedBalance($reconciliation);

        return $line->refresh();
    }

    /**
     * Exclude a statement line from reconciliation (e.g., bank fees to post later).
     */
    public function exclude(BankStatementLine $line): BankStatementLine
    {
        $line->update(['status' => 'excluded']);
        $this->recalculateAdjustedBalance($line->reconciliation);

        return $line->refresh();
    }

    /**
     * Complete the reconciliation.
     *
     * @throws ValidationException
     */
    public function complete(BankReconciliation $reconciliation): BankReconciliation
    {
        if ($reconciliation->isCompleted()) {
            throw ValidationException::withMessages([
                'reconciliation' => ['This reconciliation is already completed.'],
            ]);
        }

        $reconciliation->update([
            'status' => 'completed',
            'reconciled_at' => now(),
            'reconciled_by' => auth()->id(),
        ]);

        return $reconciliation->refresh();
    }

    /**
     * Get the reconciliation summary with outstanding items.
     *
     * @return array<string, mixed>
     */
    public function summary(BankReconciliation $reconciliation): array
    {
        $lines = $reconciliation->statementLines;

        $matchedDeposits = $lines->where('status', 'matched')->where('type', 'deposit')->sum('amount');
        $matchedWithdrawals = $lines->where('status', 'matched')->where('type', 'withdrawal')->sum('amount');
        $unmatchedDeposits = $lines->where('status', 'unmatched')->where('type', 'deposit')->sum('amount');
        $unmatchedWithdrawals = $lines->where('status', 'unmatched')->where('type', 'withdrawal')->sum('amount');

        // Outstanding GL entries (in books but not on statement)
        $outstandingGL = $this->getOutstandingGLEntries($reconciliation);

        return [
            'reconciliation_id' => $reconciliation->id,
            'account' => [
                'id' => $reconciliation->account_id,
                'code' => $reconciliation->account?->code,
                'name_ar' => $reconciliation->account?->name_ar,
                'name_en' => $reconciliation->account?->name_en,
            ],
            'statement_date' => $reconciliation->statement_date->toDateString(),
            'statement_balance' => (string) $reconciliation->statement_balance,
            'book_balance' => (string) $reconciliation->book_balance,
            'adjusted_book_balance' => (string) $reconciliation->adjusted_book_balance,
            'difference' => $reconciliation->difference(),
            'status' => $reconciliation->status,
            'counts' => [
                'total_lines' => $lines->count(),
                'matched' => $lines->where('status', 'matched')->count(),
                'unmatched' => $lines->where('status', 'unmatched')->count(),
                'excluded' => $lines->where('status', 'excluded')->count(),
            ],
            'matched_totals' => [
                'deposits' => number_format((float) $matchedDeposits, 2, '.', ''),
                'withdrawals' => number_format(abs((float) $matchedWithdrawals), 2, '.', ''),
            ],
            'unmatched_totals' => [
                'deposits' => number_format((float) $unmatchedDeposits, 2, '.', ''),
                'withdrawals' => number_format(abs((float) $unmatchedWithdrawals), 2, '.', ''),
            ],
            'outstanding_gl_entries' => $outstandingGL,
        ];
    }

    /**
     * Get GL entries for the bank account not matched to any statement line.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getOutstandingGLEntries(BankReconciliation $reconciliation): array
    {
        $tenantId = (int) app('tenant.id');

        $matchedGLIds = $reconciliation->statementLines()
            ->where('status', 'matched')
            ->whereNotNull('journal_entry_line_id')
            ->pluck('journal_entry_line_id')
            ->toArray();

        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entry_lines.account_id', $reconciliation->account_id)
            ->where('journal_entries.date', '<=', $reconciliation->statement_date)
            ->select(
                'journal_entry_lines.id',
                'journal_entries.date',
                'journal_entries.entry_number',
                'journal_entry_lines.description',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
            )
            ->orderBy('journal_entries.date');

        if (! empty($matchedGLIds)) {
            $query->whereNotIn('journal_entry_lines.id', $matchedGLIds);
        }

        return $query->get()->map(fn ($row) => [
            'id' => $row->id,
            'date' => $row->date,
            'entry_number' => $row->entry_number,
            'description' => $row->description,
            'debit' => number_format((float) $row->debit, 2, '.', ''),
            'credit' => number_format((float) $row->credit, 2, '.', ''),
            'amount' => number_format((float) $row->debit - (float) $row->credit, 2, '.', ''),
        ])->toArray();
    }

    /**
     * Get the GL book balance for an account as of a date.
     */
    private function getBookBalance(int $accountId, string $asOfDate): string
    {
        $tenantId = (int) app('tenant.id');

        $result = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entry_lines.account_id', $accountId)
            ->where('journal_entries.date', '<=', $asOfDate)
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) - COALESCE(SUM(journal_entry_lines.credit), 0) as balance')
            ->first();

        return number_format((float) ($result->balance ?? 0), 2, '.', '');
    }

    /**
     * Recalculate the adjusted book balance after matching changes.
     */
    private function recalculateAdjustedBalance(BankReconciliation $reconciliation): void
    {
        // Adjusted book balance = book balance + unmatched deposits - unmatched withdrawals
        $unmatchedDeposits = (float) $reconciliation->statementLines()
            ->where('status', 'unmatched')
            ->where('type', 'deposit')
            ->sum('amount');

        // Withdrawals are stored as negative amounts — take absolute value
        $unmatchedWithdrawals = abs((float) $reconciliation->statementLines()
            ->where('status', 'unmatched')
            ->where('type', 'withdrawal')
            ->sum('amount'));

        $adjusted = bcadd(
            (string) $reconciliation->book_balance,
            bcsub(
                number_format($unmatchedDeposits, 2, '.', ''),
                number_format($unmatchedWithdrawals, 2, '.', ''),
                2,
            ),
            2,
        );

        $reconciliation->update(['adjusted_book_balance' => $adjusted]);
    }
}
