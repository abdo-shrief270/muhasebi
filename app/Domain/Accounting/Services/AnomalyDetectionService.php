<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\AccountsPayable\Models\BillPayment;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Billing\Models\Invoice;
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
        $query = Invoice::query()
            ->select('id', 'client_id', 'invoice_number', 'total', 'date')
            ->orderBy('client_id')
            ->orderBy('date');

        if (! empty($filters['from'])) {
            $query->where('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('date', '<=', $filters['to']);
        }

        $invoices = $query->get();

        $anomalies = [];
        $grouped = $invoices->groupBy(fn (Invoice $inv) => $inv->client_id.'|'.$inv->total);

        foreach ($grouped as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $items = $group->values();
            for ($i = 0; $i < $items->count(); $i++) {
                for ($j = $i + 1; $j < $items->count(); $j++) {
                    $a = $items[$i];
                    $b = $items[$j];

                    $daysDiff = abs(Carbon::parse($a->date)->diffInDays(Carbon::parse($b->date)));

                    if ($daysDiff <= 3) {
                        $anomalies[] = [
                            'type' => 'duplicate_invoice',
                            'severity' => 'high',
                            'description' => "Possible duplicate invoices: {$a->invoice_number} and {$b->invoice_number} (same client, amount {$a->total}, {$daysDiff} days apart)",
                            'description_ar' => "فواتير مكررة محتملة: {$a->invoice_number} و {$b->invoice_number} (نفس العميل، المبلغ {$a->total}، فارق {$daysDiff} أيام)",
                            'details' => [
                                'invoice_a_id' => $a->id,
                                'invoice_b_id' => $b->id,
                                'invoice_a_number' => $a->invoice_number,
                                'invoice_b_number' => $b->invoice_number,
                                'client_id' => $a->client_id,
                                'amount' => $a->total,
                                'days_apart' => $daysDiff,
                            ],
                            'detected_at' => now()->toIso8601String(),
                        ];
                    }
                }
            }
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

        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.date', '>=', $twelveMonthsAgo)
            ->select(
                'journal_entry_lines.id',
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entries.entry_number',
                'journal_entries.date',
            );

        if (! empty($filters['from'])) {
            $query->where('journal_entries.date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('journal_entries.date', '<=', $filters['to']);
        }

        $lines = $query->get();

        $anomalies = [];
        $grouped = $lines->groupBy('account_id');

        foreach ($grouped as $accountId => $accountLines) {
            if ($accountLines->count() < 5) {
                continue; // Need enough data for meaningful statistics
            }

            $amounts = $accountLines->map(fn ($line) => bcadd((string) $line->debit, (string) $line->credit, 2));

            // Calculate mean using bcmath
            $sum = '0.00';
            foreach ($amounts as $amount) {
                $sum = bcadd($sum, $amount, 2);
            }
            $count = $amounts->count();
            $mean = bcdiv($sum, (string) $count, 2);

            // Calculate variance: sum((x - mean)^2) / count
            $varianceSum = '0.00';
            foreach ($amounts as $amount) {
                $diff = bcsub($amount, $mean, 10);
                $squared = bcmul($diff, $diff, 10);
                $varianceSum = bcadd($varianceSum, $squared, 10);
            }
            $variance = bcdiv($varianceSum, (string) $count, 10);

            // StdDev = sqrt(variance) — use php sqrt() on float, convert back
            $stddev = number_format(sqrt((float) $variance), 2, '.', '');

            // Threshold = mean + (3 * stddev)
            $threshold = bcadd($mean, bcmul('3', $stddev, 2), 2);

            // Flag transactions above threshold
            foreach ($accountLines as $line) {
                $lineAmount = bcadd((string) $line->debit, (string) $line->credit, 2);

                if (bccomp($lineAmount, $threshold, 2) > 0 && bccomp($stddev, '0.00', 2) > 0) {
                    $anomalies[] = [
                        'type' => 'unusual_amount',
                        'severity' => 'high',
                        'description' => "Unusual amount {$lineAmount} on entry {$line->entry_number} for account {$accountId} (mean: {$mean}, stddev: {$stddev}, threshold: {$threshold})",
                        'description_ar' => "مبلغ غير عادي {$lineAmount} في القيد {$line->entry_number} للحساب {$accountId} (المتوسط: {$mean}، الانحراف المعياري: {$stddev}، الحد: {$threshold})",
                        'details' => [
                            'journal_entry_line_id' => $line->id,
                            'entry_number' => $line->entry_number,
                            'account_id' => $accountId,
                            'amount' => $lineAmount,
                            'mean' => $mean,
                            'stddev' => $stddev,
                            'threshold' => $threshold,
                            'date' => $line->date,
                        ],
                        'detected_at' => now()->toIso8601String(),
                    ];
                }
            }
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
                    $q->whereRaw("EXTRACT(DOW FROM date) IN (5, 6)");
                } else {
                    $q->whereRaw("DAYOFWEEK(date) IN (6, 7)");
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
        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_entries.deleted_at')
            ->select(
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
            );

        if (! empty($filters['from'])) {
            $query->where('journal_entries.date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('journal_entries.date', '<=', $filters['to']);
        }

        $lines = $query->get();
        $grouped = $lines->groupBy('account_id');

        $anomalies = [];
        foreach ($grouped as $accountId => $accountLines) {
            $total = $accountLines->count();
            if ($total < 5) {
                continue; // Need enough transactions to be meaningful
            }

            $roundCount = 0;
            foreach ($accountLines as $line) {
                $amount = bcadd((string) $line->debit, (string) $line->credit, 2);
                // Check if amount ends in 00 (is divisible by 100)
                $intAmount = (int) bcmul($amount, '1', 0); // Truncate to integer
                if ($intAmount > 0 && $intAmount % 100 === 0) {
                    $roundCount++;
                }
            }

            $percentage = ($roundCount / $total) * 100;

            if ($percentage > 80) {
                $anomalies[] = [
                    'type' => 'round_number_bias',
                    'severity' => 'medium',
                    'description' => "Account {$accountId} has ".number_format($percentage, 1)."% round number transactions ({$roundCount}/{$total}), may indicate estimated figures",
                    'description_ar' => "الحساب {$accountId} يحتوي على ".number_format($percentage, 1)."% معاملات بأرقام مقربة ({$roundCount}/{$total})، قد تشير إلى أرقام تقديرية",
                    'details' => [
                        'account_id' => $accountId,
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
