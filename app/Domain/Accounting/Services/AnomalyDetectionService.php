<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\AccountsPayable\Models\BillPayment;
use App\Domain\Billing\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnomalyDetectionService
{
    /**
     * Run all detectors and return combined results sorted by severity.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function detectAll(array $filters = []): array
    {
        $results = collect()
            ->merge($this->duplicateInvoices($filters))
            ->merge($this->unusualAmounts($filters))
            ->merge($this->missingSequences($filters))
            ->merge($this->weekendEntries($filters))
            ->merge($this->roundNumberBias($filters))
            ->merge($this->unusualVendorPayments($filters))
            ->merge($this->dormantAccountActivity($filters));

        $severityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];

        return $results->sortBy(fn (array $item) => $severityOrder[$item['severity']] ?? 3)->values()->all();
    }

    /**
     * Find invoices with same client + amount + date (within 3 days).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function duplicateInvoices(array $filters = []): array
    {
        // Use a self-join in SQL to find duplicate pairs (same client, same total, within 3 days)
        $query = DB::table('invoices as a')
            ->join('invoices as b', function ($join) {
                $join->on('a.client_id', '=', 'b.client_id')
                    ->on('a.total', '=', 'b.total')
                    ->whereColumn('a.id', '<', 'b.id');
            })
            // Postgres: subtracting two dates yields an integer day count.
            ->whereRaw('ABS(a.date - b.date) <= 3')
            ->select(
                'a.id as a_id', 'a.invoice_number as a_number', 'a.client_id', 'a.total', 'a.date as a_date',
                'b.id as b_id', 'b.invoice_number as b_number', 'b.date as b_date',
            );

        if (! empty($filters['from'])) {
            $query->where('a.date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('a.date', '<=', $filters['to']);
        }

        $pairs = $query->get();

        $anomalies = [];
        foreach ($pairs as $pair) {
            $daysDiff = abs(Carbon::parse($pair->a_date)->diffInDays(Carbon::parse($pair->b_date)));
            $anomalies[] = [
                'type' => 'duplicate_invoice',
                'severity' => 'high',
                'description' => "Possible duplicate invoices: {$pair->a_number} and {$pair->b_number} (same client, amount {$pair->total}, {$daysDiff} days apart)",
                'description_ar' => "فواتير مكررة محتملة: {$pair->a_number} و {$pair->b_number} (نفس العميل، المبلغ {$pair->total}، فارق {$daysDiff} أيام)",
                'details' => [
                    'invoice_a_id' => $pair->a_id,
                    'invoice_b_id' => $pair->b_id,
                    'invoice_a_number' => $pair->a_number,
                    'invoice_b_number' => $pair->b_number,
                    'client_id' => $pair->client_id,
                    'amount' => $pair->total,
                    'days_apart' => $daysDiff,
                ],
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return $anomalies;
    }

    /**
     * For each account, flag transactions where amount > mean + (3 * stddev).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function unusualAmounts(array $filters = []): array
    {
        $twelveMonthsAgo = now()->subMonths(12)->startOfMonth();

        // Step 1: Compute per-account statistics (mean, stddev) in SQL
        $statsQuery = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.date', '>=', $twelveMonthsAgo);

        if (! empty($filters['from'])) {
            $statsQuery->where('journal_entries.date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $statsQuery->where('journal_entries.date', '<=', $filters['to']);
        }

        $stats = (clone $statsQuery)
            ->select(
                'journal_entry_lines.account_id',
                DB::raw('COUNT(*) as line_count'),
                DB::raw('AVG(journal_entry_lines.debit + journal_entry_lines.credit) as mean_amount'),
                DB::raw('STDDEV_POP(journal_entry_lines.debit + journal_entry_lines.credit) as stddev_amount'),
            )
            ->groupBy('journal_entry_lines.account_id')
            // Postgres can't reference SELECT aliases inside HAVING.
            ->havingRaw('COUNT(*) >= 5')
            ->havingRaw('STDDEV_POP(journal_entry_lines.debit + journal_entry_lines.credit) > 0')
            ->get()
            ->keyBy('account_id');

        if ($stats->isEmpty()) {
            return [];
        }

        // Step 2: Build per-account thresholds and fetch only outlier lines
        // Use a single query with a subquery join for thresholds
        $accountThresholds = $stats->map(fn ($s) => [
            'account_id' => $s->account_id,
            'mean' => Money::of($s->mean_amount),
            'stddev' => Money::of($s->stddev_amount),
            // threshold stays float — used downstream as a numeric comparator, not displayed
            'threshold' => (float) $s->mean_amount + 3 * (float) $s->stddev_amount,
        ]);

        // Fetch only the lines that exceed their account's threshold
        $outlierQuery = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.date', '>=', $twelveMonthsAgo)
            ->whereIn('journal_entry_lines.account_id', $accountThresholds->pluck('account_id')->toArray())
            ->select(
                'journal_entry_lines.id',
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entries.entry_number',
                'journal_entries.date',
            );

        if (! empty($filters['from'])) {
            $outlierQuery->where('journal_entries.date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $outlierQuery->where('journal_entries.date', '<=', $filters['to']);
        }

        // Build WHERE conditions to only fetch outlier rows
        $outlierQuery->where(function ($q) use ($accountThresholds) {
            foreach ($accountThresholds as $t) {
                $q->orWhere(function ($sq) use ($t) {
                    $sq->where('journal_entry_lines.account_id', $t['account_id'])
                        ->whereRaw('(journal_entry_lines.debit + journal_entry_lines.credit) > ?', [$t['threshold']]);
                });
            }
        });

        $outlierLines = $outlierQuery->get();

        $anomalies = [];
        foreach ($outlierLines as $line) {
            $t = $accountThresholds[$line->account_id];
            $lineAmount = bcadd((string) $line->debit, (string) $line->credit, 2);
            $mean = $t['mean'];
            $stddev = $t['stddev'];
            $threshold = number_format($t['threshold'], 2, '.', '');

            $anomalies[] = [
                'type' => 'unusual_amount',
                'severity' => 'high',
                'description' => "Unusual amount {$lineAmount} on entry {$line->entry_number} for account {$line->account_id} (mean: {$mean}, stddev: {$stddev}, threshold: {$threshold})",
                'description_ar' => "مبلغ غير عادي {$lineAmount} في القيد {$line->entry_number} للحساب {$line->account_id} (المتوسط: {$mean}، الانحراف المعياري: {$stddev}، الحد: {$threshold})",
                'details' => [
                    'journal_entry_line_id' => $line->id,
                    'entry_number' => $line->entry_number,
                    'account_id' => $line->account_id,
                    'amount' => $lineAmount,
                    'mean' => $mean,
                    'stddev' => $stddev,
                    'threshold' => $threshold,
                    'date' => $line->date,
                ],
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return $anomalies;
    }

    /**
     * Check invoice numbers and journal entry numbers for gaps in sequences.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function missingSequences(array $filters = []): array
    {
        $anomalies = [];

        // Check invoice number sequences
        $invoiceQuery = Invoice::query()->select('invoice_number')->orderBy('invoice_number');
        if (! empty($filters['from'])) {
            $invoiceQuery->where('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $invoiceQuery->where('date', '<=', $filters['to']);
        }

        $invoiceNumbers = $invoiceQuery->pluck('invoice_number');
        $invoiceGaps = $this->findSequenceGaps($invoiceNumbers, 'INV-');
        foreach ($invoiceGaps as $gap) {
            $anomalies[] = [
                'type' => 'missing_sequence',
                'severity' => 'medium',
                'description' => "Missing invoice number: {$gap['missing']} (between {$gap['before']} and {$gap['after']})",
                'description_ar' => "رقم فاتورة مفقود: {$gap['missing']} (بين {$gap['before']} و {$gap['after']})",
                'details' => [
                    'sequence_type' => 'invoice',
                    'missing_number' => $gap['missing'],
                    'before' => $gap['before'],
                    'after' => $gap['after'],
                ],
                'detected_at' => now()->toIso8601String(),
            ];
        }

        // Check journal entry number sequences
        $jeQuery = JournalEntry::query()->select('entry_number')->orderBy('entry_number');
        if (! empty($filters['from'])) {
            $jeQuery->where('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $jeQuery->where('date', '<=', $filters['to']);
        }

        $entryNumbers = $jeQuery->pluck('entry_number');
        $jeGaps = $this->findSequenceGaps($entryNumbers, 'JE-');
        foreach ($jeGaps as $gap) {
            $anomalies[] = [
                'type' => 'missing_sequence',
                'severity' => 'medium',
                'description' => "Missing journal entry number: {$gap['missing']} (between {$gap['before']} and {$gap['after']})",
                'description_ar' => "رقم قيد يومية مفقود: {$gap['missing']} (بين {$gap['before']} و {$gap['after']})",
                'details' => [
                    'sequence_type' => 'journal_entry',
                    'missing_number' => $gap['missing'],
                    'before' => $gap['before'],
                    'after' => $gap['after'],
                ],
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return $anomalies;
    }

    /**
     * Flag journal entries posted on weekends (Friday/Saturday in Egypt).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function weekendEntries(array $filters = []): array
    {
        $query = JournalEntry::query()
            ->where('status', 'posted')
            ->where(function ($q) {
                // Friday = 5, Saturday = 6 in Carbon (dayOfWeek)
                // DAYOFWEEK in MySQL: 1=Sunday ... 7=Saturday; 6=Friday, 7=Saturday
                // For PostgreSQL use EXTRACT(DOW ...) : 0=Sunday ... 6=Saturday; 5=Friday, 6=Saturday
                // Using raw for DB compatibility — detect driver
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    $q->whereRaw('EXTRACT(DOW FROM date) IN (5, 6)');
                } else {
                    $q->whereRaw('DAYOFWEEK(date) IN (6, 7)');
                }
            })
            ->select('id', 'entry_number', 'date', 'description', 'total_debit');

        if (! empty($filters['from'])) {
            $query->where('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('date', '<=', $filters['to']);
        }

        $entries = $query->get();

        $anomalies = [];
        foreach ($entries as $entry) {
            $dayName = Carbon::parse($entry->date)->format('l');
            $anomalies[] = [
                'type' => 'weekend_entry',
                'severity' => 'low',
                'description' => "Journal entry {$entry->entry_number} posted on {$dayName} ({$entry->date->format('Y-m-d')})",
                'description_ar' => "قيد يومية {$entry->entry_number} مسجل يوم {$dayName} ({$entry->date->format('Y-m-d')})",
                'details' => [
                    'journal_entry_id' => $entry->id,
                    'entry_number' => $entry->entry_number,
                    'date' => $entry->date->format('Y-m-d'),
                    'day_of_week' => $dayName,
                    'amount' => $entry->total_debit,
                ],
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return $anomalies;
    }

    /**
     * Flag accounts where >80% of transactions are round numbers (ending in 00, 000).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function roundNumberBias(array $filters = []): array
    {
        // Push round-number counting to SQL using CASE WHEN and GROUP BY
        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_entries.deleted_at');

        if (! empty($filters['from'])) {
            $query->where('journal_entries.date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('journal_entries.date', '<=', $filters['to']);
        }

        $results = $query->select(
            'journal_entry_lines.account_id',
            DB::raw('COUNT(*) as total_transactions'),
            // UNSIGNED is MySQL; Postgres uses BIGINT. MOD() is also
            // cross-DB-safe here since we're already working with integers.
            DB::raw('SUM(CASE WHEN CAST(journal_entry_lines.debit + journal_entry_lines.credit AS BIGINT) > 0 AND MOD(CAST(journal_entry_lines.debit + journal_entry_lines.credit AS BIGINT), 100) = 0 THEN 1 ELSE 0 END) as round_transactions'),
        )
            ->groupBy('journal_entry_lines.account_id')
            ->havingRaw('COUNT(*) >= 5')
            ->get();

        $anomalies = [];
        foreach ($results as $row) {
            $total = (int) $row->total_transactions;
            $roundCount = (int) $row->round_transactions;
            $percentage = ($roundCount / $total) * 100;

            if ($percentage > 80) {
                $anomalies[] = [
                    'type' => 'round_number_bias',
                    'severity' => 'medium',
                    'description' => "Account {$row->account_id} has ".number_format($percentage, 1)."% round number transactions ({$roundCount}/{$total}), may indicate estimated figures",
                    'description_ar' => "الحساب {$row->account_id} يحتوي على ".number_format($percentage, 1)."% معاملات بأرقام مقربة ({$roundCount}/{$total})، قد تشير إلى أرقام تقديرية",
                    'details' => [
                        'account_id' => $row->account_id,
                        'total_transactions' => $total,
                        'round_transactions' => $roundCount,
                        'percentage' => round($percentage, 1),
                    ],
                    'detected_at' => now()->toIso8601String(),
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Flag vendors receiving payments significantly above their historical average.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function unusualVendorPayments(array $filters = []): array
    {
        $payments = BillPayment::query()
            ->select('id', 'vendor_id', 'amount', 'payment_date', 'reference')
            ->orderBy('vendor_id')
            ->orderBy('payment_date')
            ->get();

        $grouped = $payments->groupBy('vendor_id');
        $anomalies = [];

        foreach ($grouped as $vendorId => $vendorPayments) {
            if ($vendorPayments->count() < 5) {
                continue;
            }

            // Calculate mean and stddev using bcmath
            $sum = '0.00';
            $amounts = [];
            foreach ($vendorPayments as $payment) {
                $amount = (string) $payment->amount;
                $sum = bcadd($sum, $amount, 2);
                $amounts[] = $amount;
            }

            $count = count($amounts);
            $mean = bcdiv($sum, (string) $count, 2);

            $varianceSum = '0.00';
            foreach ($amounts as $amount) {
                $diff = bcsub($amount, $mean, 10);
                $squared = bcmul($diff, $diff, 10);
                $varianceSum = bcadd($varianceSum, $squared, 10);
            }
            $variance = bcdiv($varianceSum, (string) $count, 10);
            $stddev = number_format(sqrt((float) $variance), 2, '.', '');

            $threshold = bcadd($mean, bcmul('3', $stddev, 2), 2);

            foreach ($vendorPayments as $payment) {
                $paymentAmount = (string) $payment->amount;
                if (bccomp($paymentAmount, $threshold, 2) > 0 && bccomp($stddev, '0.00', 2) > 0) {
                    $anomalies[] = [
                        'type' => 'unusual_vendor_payment',
                        'severity' => 'high',
                        'description' => "Unusual payment of {$paymentAmount} to vendor {$vendorId} (average: {$mean}, threshold: {$threshold})",
                        'description_ar' => "دفعة غير عادية بمبلغ {$paymentAmount} للمورد {$vendorId} (المتوسط: {$mean}، الحد: {$threshold})",
                        'details' => [
                            'bill_payment_id' => $payment->id,
                            'vendor_id' => $vendorId,
                            'amount' => $paymentAmount,
                            'mean' => $mean,
                            'stddev' => $stddev,
                            'threshold' => $threshold,
                            'payment_date' => $payment->payment_date,
                        ],
                        'detected_at' => now()->toIso8601String(),
                    ];
                }
            }
        }

        return $anomalies;
    }

    /**
     * Flag accounts with no activity for 6+ months that suddenly have transactions.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function dormantAccountActivity(array $filters = []): array
    {
        $sixMonthsAgo = now()->subMonths(6);

        // Find accounts that have recent activity
        $recentlyActiveAccounts = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.date', '>=', $sixMonthsAgo)
            ->select('journal_entry_lines.account_id')
            ->distinct()
            ->pluck('account_id');

        if ($recentlyActiveAccounts->isEmpty()) {
            return [];
        }

        $anomalies = [];

        foreach ($recentlyActiveAccounts as $accountId) {
            // Check if this account had any activity in the 6 months before the dormant period
            $lastActivityBeforeDormant = JournalEntryLine::query()
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entries.status', 'posted')
                ->whereNull('journal_entries.deleted_at')
                ->where('journal_entry_lines.account_id', $accountId)
                ->where('journal_entries.date', '<', $sixMonthsAgo)
                ->orderByDesc('journal_entries.date')
                ->value('journal_entries.date');

            if ($lastActivityBeforeDormant === null) {
                continue; // New account, not dormant
            }

            $lastActivityDate = Carbon::parse($lastActivityBeforeDormant);
            $monthsInactive = $lastActivityDate->diffInMonths($sixMonthsAgo);

            if ($monthsInactive >= 6) {
                // Get the recent transaction details
                $recentEntry = JournalEntryLine::query()
                    ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                    ->where('journal_entries.status', 'posted')
                    ->whereNull('journal_entries.deleted_at')
                    ->where('journal_entry_lines.account_id', $accountId)
                    ->where('journal_entries.date', '>=', $sixMonthsAgo)
                    ->orderBy('journal_entries.date')
                    ->select('journal_entries.entry_number', 'journal_entries.date', 'journal_entry_lines.debit', 'journal_entry_lines.credit')
                    ->first();

                if ($recentEntry) {
                    $amount = bcadd((string) $recentEntry->debit, (string) $recentEntry->credit, 2);
                    $anomalies[] = [
                        'type' => 'dormant_account_activity',
                        'severity' => 'medium',
                        'description' => "Account {$accountId} was dormant for {$monthsInactive} months, new activity detected on entry {$recentEntry->entry_number}",
                        'description_ar' => "الحساب {$accountId} كان خاملاً لمدة {$monthsInactive} أشهر، تم اكتشاف نشاط جديد في القيد {$recentEntry->entry_number}",
                        'details' => [
                            'account_id' => $accountId,
                            'months_inactive' => $monthsInactive,
                            'last_activity_before' => $lastActivityDate->format('Y-m-d'),
                            'new_activity_entry' => $recentEntry->entry_number,
                            'new_activity_date' => $recentEntry->date,
                            'new_activity_amount' => $amount,
                        ],
                        'detected_at' => now()->toIso8601String(),
                    ];
                }
            }
        }

        return $anomalies;
    }

    /**
     * Find gaps in a numbered sequence like INV-000001, INV-000002, etc.
     *
     * @param  Collection<int, string>  $numbers
     * @return array<int, array{missing: string, before: string, after: string}>
     */
    private function findSequenceGaps(Collection $numbers, string $prefix): array
    {
        $parsed = $numbers
            ->map(function (string $number) use ($prefix) {
                $numPart = str_replace($prefix, '', $number);
                if (! is_numeric($numPart)) {
                    return null;
                }

                return [
                    'original' => $number,
                    'numeric' => (int) $numPart,
                    'pad_length' => strlen($numPart),
                ];
            })
            ->filter()
            ->sortBy('numeric')
            ->values();

        if ($parsed->count() < 2) {
            return [];
        }

        $gaps = [];
        for ($i = 0; $i < $parsed->count() - 1; $i++) {
            $current = $parsed[$i];
            $next = $parsed[$i + 1];

            for ($n = $current['numeric'] + 1; $n < $next['numeric']; $n++) {
                $padLength = max($current['pad_length'], $next['pad_length']);
                $gaps[] = [
                    'missing' => $prefix.str_pad((string) $n, $padLength, '0', STR_PAD_LEFT),
                    'before' => $current['original'],
                    'after' => $next['original'],
                ];
            }
        }

        return $gaps;
    }
}
