<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\BankReconciliation;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Services\BillPaymentService;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\PaymentService;
use App\Domain\Client\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BankAutoReconciliationService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly BillPaymentService $billPaymentService,
    ) {}

    /**
     * Enhanced smart matching of bank statement lines against invoices and bills.
     *
     * @return array{matched: int, unmatched: int, confidence_distribution: array<string, int>}
     */
    public function smartMatch(BankReconciliation $recon): array
    {
        $tenantId = (int) app('tenant.id');
        $matched = 0;
        $unmatched = 0;
        $distribution = ['high' => 0, 'medium' => 0, 'low' => 0];

        // Pre-load all candidate invoices and bills once to avoid N+1 queries per chunk
        $invoices = Invoice::where('tenant_id', $tenantId)
            ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
            ->with('client')
            ->get();

        $bills = Bill::where('tenant_id', $tenantId)
            ->whereIn('status', ['approved', 'partially_paid', 'overdue'])
            ->with('vendor')
            ->get();

        $recon->statementLines()->unmatched()->where('auto_matched', false)->chunk(200, function ($lines) use ($invoices, $bills, &$matched, &$unmatched, &$distribution) {
            foreach ($lines as $line) {
                $result = $this->matchLine($line, $invoices, $bills);

                if ($result !== null) {
                    $matched++;

                    if ($result >= 80) {
                        $distribution['high']++;
                    } elseif ($result >= 60) {
                        $distribution['medium']++;
                    } else {
                        $distribution['low']++;
                    }
                } else {
                    $unmatched++;
                }
            }
        });

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'confidence_distribution' => $distribution,
        ];
    }

    /**
     * Match a single line. Returns confidence score or null if no match.
     *
     * @param  Collection  $invoices  Pre-loaded invoices
     * @param  Collection  $bills  Pre-loaded bills
     */
    private function matchLine(BankStatementLine $line, Collection $invoices, Collection $bills): ?float
    {
        $amount = abs((float) $line->amount);
        $amountStr = (string) $amount;
        $isDeposit = $line->isDeposit();

        // Deposits match invoices, withdrawals match bills
        if ($isDeposit) {
            return $this->matchAgainstInvoices($line, $amountStr, $invoices);
        }

        return $this->matchAgainstBills($line, $amountStr, $bills);
    }

    /**
     * Try matching a deposit line against invoices (exact, tolerance, description).
     *
     * @param  Collection  $invoices  Pre-loaded invoices
     */
    private function matchAgainstInvoices(BankStatementLine $line, string $amount, Collection $invoices): ?float
    {

        // Pass 1: Exact amount + reference match (confidence 100)
        if ($line->reference) {
            foreach ($invoices as $invoice) {
                if (bccomp($amount, (string) $invoice->total, 2) === 0
                    && $this->referenceMatches($line->reference, $invoice->invoice_number)) {
                    $this->applyInvoiceMatch($line, $invoice, 100.00);

                    return 100.00;
                }
            }
        }

        // Pass 2: Amount within 5% tolerance + date within 3 days (confidence 80)
        foreach ($invoices as $invoice) {
            $invoiceTotal = (string) $invoice->total;
            $tolerance = bcmul($invoiceTotal, '0.05', 2);
            $diff = bcsub($amount, $invoiceTotal, 2);
            $absDiff = ltrim($diff, '-');

            if (bccomp($absDiff, $tolerance, 2) <= 0) {
                // Check date proximity (within 3 days of due_date or date)
                $refDate = $invoice->due_date ?? $invoice->date;
                if ($refDate && $line->date && abs($line->date->diffInDays($refDate)) <= 3) {
                    $this->applyInvoiceMatch($line, $invoice, 80.00);

                    return 80.00;
                }
            }
        }

        // Pass 3: Description keyword match against client names (confidence 60)
        if ($line->description) {
            $normalizedDesc = mb_strtolower(trim($line->description));

            foreach ($invoices as $invoice) {
                $client = $invoice->client;
                if (! $client) {
                    continue;
                }

                $clientName = mb_strtolower(trim($client->name ?? ''));
                $tradeName = mb_strtolower(trim($client->trade_name ?? ''));

                if (($clientName && str_contains($normalizedDesc, $clientName))
                    || ($tradeName && str_contains($normalizedDesc, $tradeName))) {
                    $this->applyInvoiceMatch($line, $invoice, 60.00);

                    return 60.00;
                }
            }
        }

        return null;
    }

    /**
     * Try matching a withdrawal line against bills (exact, tolerance, description).
     *
     * @param  Collection  $bills  Pre-loaded bills
     */
    private function matchAgainstBills(BankStatementLine $line, string $amount, Collection $bills): ?float
    {

        // Pass 1: Exact amount + reference match (confidence 100)
        if ($line->reference) {
            foreach ($bills as $bill) {
                if (bccomp($amount, (string) $bill->total, 2) === 0
                    && $this->referenceMatches($line->reference, $bill->bill_number)) {
                    $this->applyBillMatch($line, $bill, 100.00);

                    return 100.00;
                }
            }
        }

        // Pass 2: Amount within 5% tolerance + date within 3 days (confidence 80)
        foreach ($bills as $bill) {
            $billTotal = (string) $bill->total;
            $tolerance = bcmul($billTotal, '0.05', 2);
            $diff = bcsub($amount, $billTotal, 2);
            $absDiff = ltrim($diff, '-');

            if (bccomp($absDiff, $tolerance, 2) <= 0) {
                $refDate = $bill->due_date ?? $bill->date;
                if ($refDate && $line->date && abs($line->date->diffInDays($refDate)) <= 3) {
                    $this->applyBillMatch($line, $bill, 80.00);

                    return 80.00;
                }
            }
        }

        // Pass 3: Description keyword match against vendor names (confidence 60)
        if ($line->description) {
            $normalizedDesc = mb_strtolower(trim($line->description));

            foreach ($bills as $bill) {
                $vendor = $bill->vendor;
                if (! $vendor) {
                    continue;
                }

                $nameAr = mb_strtolower(trim($vendor->name_ar ?? ''));
                $nameEn = mb_strtolower(trim($vendor->name_en ?? ''));

                if (($nameAr && str_contains($normalizedDesc, $nameAr))
                    || ($nameEn && str_contains($normalizedDesc, $nameEn))) {
                    $this->applyBillMatch($line, $bill, 60.00);

                    return 60.00;
                }
            }
        }

        return null;
    }

    /**
     * Check if a statement line reference matches a document number.
     */
    private function referenceMatches(string $reference, ?string $documentNumber): bool
    {
        if (! $documentNumber) {
            return false;
        }

        $ref = mb_strtolower(trim($reference));
        $doc = mb_strtolower(trim($documentNumber));

        return str_contains($ref, $doc) || str_contains($doc, $ref);
    }

    /**
     * Apply an invoice match to a statement line.
     */
    private function applyInvoiceMatch(BankStatementLine $line, Invoice $invoice, float $confidence): void
    {
        $line->update([
            'auto_matched' => true,
            'match_confidence' => $confidence,
            'matched_invoice_id' => $invoice->id,
            'matched_bill_id' => null,
        ]);
    }

    /**
     * Apply a bill match to a statement line.
     */
    private function applyBillMatch(BankStatementLine $line, Bill $bill, float $confidence): void
    {
        $line->update([
            'auto_matched' => true,
            'match_confidence' => $confidence,
            'matched_bill_id' => $bill->id,
            'matched_invoice_id' => null,
        ]);
    }

    /**
     * Match a deposit line to a specific invoice. Record as payment. Auto-post GL.
     */
    public function matchToInvoice(BankStatementLine $line, int $invoiceId): BankStatementLine
    {
        $tenantId = (int) app('tenant.id');
        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($invoiceId);

        return DB::transaction(function () use ($line, $invoice): BankStatementLine {
            $payment = $this->paymentService->record($invoice, [
                'amount' => abs((float) $line->amount),
                'date' => $line->date->toDateString(),
                'method' => 'bank_transfer',
                'reference' => $line->reference,
                'notes' => "Auto-reconciled from bank statement: {$line->description}",
            ]);

            $line->update([
                'auto_matched' => true,
                'match_confidence' => 100.00,
                'matched_invoice_id' => $invoice->id,
                'matched_bill_id' => null,
                'auto_posted' => true,
                'posted_journal_entry_id' => $payment->journal_entry_id,
                'status' => 'matched',
            ]);

            return $line->refresh();
        });
    }

    /**
     * Match a withdrawal line to a specific bill. Record as bill payment. Auto-post GL.
     */
    public function matchToBill(BankStatementLine $line, int $billId): BankStatementLine
    {
        $tenantId = (int) app('tenant.id');
        $bill = Bill::where('tenant_id', $tenantId)->findOrFail($billId);

        return DB::transaction(function () use ($line, $bill): BankStatementLine {
            $payment = $this->billPaymentService->record($bill, [
                'amount' => abs((float) $line->amount),
                'date' => $line->date->toDateString(),
                'method' => 'bank_transfer',
                'reference' => $line->reference,
                'notes' => "Auto-reconciled from bank statement: {$line->description}",
            ]);

            $line->update([
                'auto_matched' => true,
                'match_confidence' => 100.00,
                'matched_bill_id' => $bill->id,
                'matched_invoice_id' => null,
                'auto_posted' => true,
                'posted_journal_entry_id' => $payment->journal_entry_id,
                'status' => 'matched',
            ]);

            return $line->refresh();
        });
    }

    /**
     * Post all high-confidence (>=80) matched lines to GL automatically.
     *
     * @return array{posted: int, skipped: int}
     */
    public function autoPost(BankReconciliation $recon): array
    {
        $posted = 0;
        $skipped = 0;

        $lines = $recon->statementLines()
            ->where('auto_matched', true)
            ->where('auto_posted', false)
            ->where('match_confidence', '>=', 80)
            ->get();

        DB::transaction(function () use ($lines, &$posted, &$skipped) {
            foreach ($lines as $line) {
                try {
                    if ($line->matched_invoice_id) {
                        $invoice = Invoice::find($line->matched_invoice_id);
                        if (! $invoice) {
                            $skipped++;

                            continue;
                        }

                        $payment = $this->paymentService->record($invoice, [
                            'amount' => abs((float) $line->amount),
                            'date' => $line->date->toDateString(),
                            'method' => 'bank_transfer',
                            'reference' => $line->reference,
                            'notes' => "Auto-posted from bank reconciliation: {$line->description}",
                        ]);

                        $line->update([
                            'auto_posted' => true,
                            'posted_journal_entry_id' => $payment->journal_entry_id,
                            'status' => 'matched',
                        ]);

                        $posted++;
                    } elseif ($line->matched_bill_id) {
                        $bill = Bill::find($line->matched_bill_id);
                        if (! $bill) {
                            $skipped++;

                            continue;
                        }

                        $payment = $this->billPaymentService->record($bill, [
                            'amount' => abs((float) $line->amount),
                            'date' => $line->date->toDateString(),
                            'method' => 'bank_transfer',
                            'reference' => $line->reference,
                            'notes' => "Auto-posted from bank reconciliation: {$line->description}",
                        ]);

                        $line->update([
                            'auto_posted' => true,
                            'posted_journal_entry_id' => $payment->journal_entry_id,
                            'status' => 'matched',
                        ]);

                        $posted++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable) {
                    $skipped++;
                }
            }
        });

        return [
            'posted' => $posted,
            'skipped' => $skipped,
        ];
    }

    /**
     * For unmatched lines, suggest possible matches from invoices/bills ordered by confidence.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unmatchedSuggestions(BankStatementLine $line): array
    {
        $tenantId = (int) app('tenant.id');
        $amount = abs((float) $line->amount);
        $amountStr = (string) $amount;
        $suggestions = [];

        if ($line->isDeposit()) {
            $invoices = Invoice::where('tenant_id', $tenantId)
                ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
                ->with('client')
                ->get();

            foreach ($invoices as $invoice) {
                $confidence = $this->calculateInvoiceConfidence($line, $invoice, $amountStr);
                if ($confidence > 0) {
                    $suggestions[] = [
                        'type' => 'invoice',
                        'id' => $invoice->id,
                        'number' => $invoice->invoice_number,
                        'total' => (string) $invoice->total,
                        'balance_due' => (string) $invoice->balanceDue(),
                        'client_name' => $invoice->client?->name,
                        'date' => $invoice->date?->toDateString(),
                        'due_date' => $invoice->due_date?->toDateString(),
                        'confidence' => $confidence,
                    ];
                }
            }
        } else {
            $bills = Bill::where('tenant_id', $tenantId)
                ->whereIn('status', ['approved', 'partially_paid', 'overdue'])
                ->with('vendor')
                ->get();

            foreach ($bills as $bill) {
                $confidence = $this->calculateBillConfidence($line, $bill, $amountStr);
                if ($confidence > 0) {
                    $suggestions[] = [
                        'type' => 'bill',
                        'id' => $bill->id,
                        'number' => $bill->bill_number,
                        'total' => (string) $bill->total,
                        'balance_due' => (string) $bill->balanceDue(),
                        'vendor_name' => $bill->vendor?->name_en ?? $bill->vendor?->name_ar,
                        'date' => $bill->date?->toDateString(),
                        'due_date' => $bill->due_date?->toDateString(),
                        'confidence' => $confidence,
                    ];
                }
            }
        }

        // Sort by confidence descending
        usort($suggestions, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return array_slice($suggestions, 0, 10);
    }

    /**
     * Calculate confidence score for an invoice match.
     */
    private function calculateInvoiceConfidence(BankStatementLine $line, Invoice $invoice, string $amount): float
    {
        // Exact amount + reference
        if ($line->reference && bccomp($amount, (string) $invoice->total, 2) === 0
            && $this->referenceMatches($line->reference, $invoice->invoice_number)) {
            return 100.00;
        }

        // Amount within 5% tolerance + date within 3 days
        $invoiceTotal = (string) $invoice->total;
        $tolerance = bcmul($invoiceTotal, '0.05', 2);
        $diff = bcsub($amount, $invoiceTotal, 2);
        $absDiff = ltrim($diff, '-');

        if (bccomp($absDiff, $tolerance, 2) <= 0) {
            $refDate = $invoice->due_date ?? $invoice->date;
            if ($refDate && $line->date && abs($line->date->diffInDays($refDate)) <= 3) {
                return 80.00;
            }

            // Amount match alone (no date proximity)
            return 50.00;
        }

        // Description keyword match
        if ($line->description && $invoice->client) {
            $normalizedDesc = mb_strtolower(trim($line->description));
            $clientName = mb_strtolower(trim($invoice->client->name ?? ''));
            $tradeName = mb_strtolower(trim($invoice->client->trade_name ?? ''));

            if (($clientName && str_contains($normalizedDesc, $clientName))
                || ($tradeName && str_contains($normalizedDesc, $tradeName))) {
                return 60.00;
            }
        }

        return 0;
    }

    /**
     * Calculate confidence score for a bill match.
     */
    private function calculateBillConfidence(BankStatementLine $line, Bill $bill, string $amount): float
    {
        // Exact amount + reference
        if ($line->reference && bccomp($amount, (string) $bill->total, 2) === 0
            && $this->referenceMatches($line->reference, $bill->bill_number)) {
            return 100.00;
        }

        // Amount within 5% tolerance + date within 3 days
        $billTotal = (string) $bill->total;
        $tolerance = bcmul($billTotal, '0.05', 2);
        $diff = bcsub($amount, $billTotal, 2);
        $absDiff = ltrim($diff, '-');

        if (bccomp($absDiff, $tolerance, 2) <= 0) {
            $refDate = $bill->due_date ?? $bill->date;
            if ($refDate && $line->date && abs($line->date->diffInDays($refDate)) <= 3) {
                return 80.00;
            }

            return 50.00;
        }

        // Description keyword match
        if ($line->description && $bill->vendor) {
            $normalizedDesc = mb_strtolower(trim($line->description));
            $nameAr = mb_strtolower(trim($bill->vendor->name_ar ?? ''));
            $nameEn = mb_strtolower(trim($bill->vendor->name_en ?? ''));

            if (($nameAr && str_contains($normalizedDesc, $nameAr))
                || ($nameEn && str_contains($normalizedDesc, $nameEn))) {
                return 60.00;
            }
        }

        return 0;
    }
}
