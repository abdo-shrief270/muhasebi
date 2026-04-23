<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Verifies core General-Ledger invariants for a tenant in a single read-only
 * pass, returning a structured report the caller can log, alert on, or surface
 * in an admin dashboard. Intended to run nightly as a defence-in-depth check
 * — if any write path slipped past validation, the next reconciliation flags
 * the discrepancy rather than letting it compound silently.
 *
 * This service never mutates data. Fix-up is a human decision.
 */
class GlReconciliationService
{
    /**
     * @return array{
     *     tenant_id: int,
     *     checked_at: string,
     *     ok: bool,
     *     total_debit: string,
     *     total_credit: string,
     *     variance: string,
     *     entries_checked: int,
     *     unbalanced_entries: list<array{id: int, entry_number: string, total_debit: string, total_credit: string}>,
     *     line_sum_mismatches: list<array{id: int, entry_number: string, header_debit: string, line_debit: string, header_credit: string, line_credit: string}>,
     * }
     */
    public function reconcileTenant(int $tenantId): array
    {
        $totals = JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', JournalEntryStatus::Posted->value)
            ->selectRaw('COALESCE(SUM(total_debit), 0) AS total_debit')
            ->selectRaw('COALESCE(SUM(total_credit), 0) AS total_credit')
            ->selectRaw('COUNT(*) AS entry_count')
            ->first();

        $totalDebit = (string) ($totals->total_debit ?? '0');
        $totalCredit = (string) ($totals->total_credit ?? '0');
        $variance = bcsub($totalDebit, $totalCredit, 2);

        $unbalanced = JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', JournalEntryStatus::Posted->value)
            ->whereColumn('total_debit', '!=', 'total_credit')
            ->get(['id', 'entry_number', 'total_debit', 'total_credit'])
            ->map(fn (JournalEntry $e) => [
                'id' => $e->id,
                'entry_number' => $e->entry_number,
                'total_debit' => (string) $e->total_debit,
                'total_credit' => (string) $e->total_credit,
            ])
            ->all();

        // Header vs line-sum mismatch detection. Catches the case where lines
        // were mutated after the header totals were computed — the individual
        // entry looks balanced by its header but its actual lines don't add up.
        // Using the query builder (not Eloquent) because the aliased SUM columns
        // don't exist on the JournalEntryLine model — stdClass access is fine.
        $lineSums = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->groupBy('journal_entry_lines.journal_entry_id')
            ->selectRaw('journal_entry_lines.journal_entry_id AS je_id')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) AS line_debit')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) AS line_credit')
            ->get();

        $lineMismatches = [];
        if ($lineSums->isNotEmpty()) {
            $entries = JournalEntry::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $lineSums->pluck('je_id')->all())
                ->get(['id', 'entry_number', 'total_debit', 'total_credit'])
                ->keyBy('id');

            foreach ($lineSums as $sum) {
                $entry = $entries->get($sum->je_id);
                if (! $entry) {
                    continue;
                }
                $headerDebit = (string) $entry->total_debit;
                $headerCredit = (string) $entry->total_credit;
                $lineDebit = (string) $sum->line_debit;
                $lineCredit = (string) $sum->line_credit;

                if (bccomp($headerDebit, $lineDebit, 2) !== 0 || bccomp($headerCredit, $lineCredit, 2) !== 0) {
                    $lineMismatches[] = [
                        'id' => $entry->id,
                        'entry_number' => $entry->entry_number,
                        'header_debit' => $headerDebit,
                        'line_debit' => $lineDebit,
                        'header_credit' => $headerCredit,
                        'line_credit' => $lineCredit,
                    ];
                }
            }
        }

        $ok = bccomp($variance, '0', 2) === 0 && $unbalanced === [] && $lineMismatches === [];

        return [
            'tenant_id' => $tenantId,
            'checked_at' => now()->toIso8601String(),
            'ok' => $ok,
            'total_debit' => (string) bcadd($totalDebit, '0', 2),
            'total_credit' => (string) bcadd($totalCredit, '0', 2),
            'variance' => $variance,
            'entries_checked' => (int) ($totals->entry_count ?? 0),
            'unbalanced_entries' => $unbalanced,
            'line_sum_mismatches' => $lineMismatches,
        ];
    }
}
