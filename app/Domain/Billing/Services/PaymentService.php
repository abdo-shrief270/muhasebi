<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Accounting\Services\GLPostingService;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly InvoiceService $invoiceService,
        private readonly GLPostingService $glPostingService,
    ) {}

    /**
     * List payments with search, filter, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Payment::query()
            ->with(['invoice.client'])
            ->when(isset($filters['invoice_id']), fn ($q) => $q->forInvoice((int) $filters['invoice_id']))
            ->when(
                isset($filters['client_id']),
                fn ($q) => $q->whereHas('invoice', fn ($iq) => $iq->forClient((int) $filters['client_id']))
            )
            ->when(isset($filters['date_from']), fn ($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']))
            ->when(
                isset($filters['method']),
                fn ($q) => $q->ofMethod(
                    $filters['method'] instanceof PaymentMethod
                        ? $filters['method']
                        : PaymentMethod::from($filters['method'])
                )
            )
            ->orderBy('date', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Record a payment against an invoice.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function record(Invoice $invoice, array $data): Payment
    {
        if (! $invoice->status->canPay()) {
            throw ValidationException::withMessages([
                'status' => ['Payments can only be recorded for sent, partially paid, or overdue invoices.'],
            ]);
        }

        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount must be greater than zero.'],
            ]);
        }

        $balanceDue = $invoice->balanceDue();

        if (bccomp((string) $amount, (string) $balanceDue, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => ["Payment amount ({$amount}) exceeds the balance due ({$balanceDue})."],
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $amount): Payment {
            $method = $data['method'] instanceof PaymentMethod
                ? $data['method']
                : PaymentMethod::from($data['method']);

            $tenantId = (int) app('tenant.id');
            $settings = $this->invoiceService->getSettings();

            // Resolve GL accounts
            $paymentAccountId = $this->getPaymentAccountId($method, $tenantId);
            $arAccountId = $settings->ar_account_id
                ?? $this->glPostingService->resolveAccount(config('accounting.default_accounts.accounts_receivable'), $tenantId);

            // Create the journal entry for this payment
            $journalEntry = $this->glPostingService->post([
                'date' => $data['date'] ?? now()->toDateString(),
                'description' => "تحصيل دفعة - فاتورة رقم {$invoice->invoice_number}",
                'reference' => $data['reference'] ?? $invoice->invoice_number,
                'lines' => [
                    [
                        'account_id' => $paymentAccountId,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => "تحصيل دفعة - فاتورة رقم {$invoice->invoice_number}",
                    ],
                    [
                        'account_id' => $arAccountId,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => "تحصيل دفعة - فاتورة رقم {$invoice->invoice_number}",
                    ],
                ],
            ]);

            // Create payment record
            $payment = Payment::query()->create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'date' => $data['date'] ?? now()->toDateString(),
                'method' => $method,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'journal_entry_id' => $journalEntry->id,
                'created_by' => Auth::id(),
            ]);

            // Update invoice amount_paid and status
            $newAmountPaid = bcadd((string) $invoice->amount_paid, (string) $amount, 2);
            $invoice->update(['amount_paid' => $newAmountPaid]);
            $invoice->refresh();

            if ($invoice->isFullyPaid()) {
                $invoice->update(['status' => InvoiceStatus::Paid]);
            } else {
                $invoice->update(['status' => InvoiceStatus::PartiallyPaid]);
            }

            return $payment->load('invoice.client');
        });
    }

    /**
     * Delete (reverse) a payment.
     *
     * @throws ValidationException
     */
    public function delete(Payment $payment): void
    {
        $payment->load('invoice');
        $invoice = $payment->invoice;

        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['Payments on cancelled invoices cannot be deleted.'],
            ]);
        }

        DB::transaction(function () use ($payment, $invoice): void {
            // Subtract payment amount from invoice
            $newAmountPaid = bcsub((string) $invoice->amount_paid, (string) $payment->amount, 2);

            if (bccomp($newAmountPaid, '0', 2) < 0) {
                $newAmountPaid = '0.00';
            }

            $invoice->update(['amount_paid' => $newAmountPaid]);
            $invoice->refresh();

            // Recalculate invoice status
            if (bccomp((string) $invoice->amount_paid, '0', 2) === 0) {
                $invoice->update(['status' => InvoiceStatus::Sent]);
            } elseif (! $invoice->isFullyPaid()) {
                $invoice->update(['status' => InvoiceStatus::PartiallyPaid]);
            }

            // Reverse the journal entry if present
            if ($payment->journal_entry_id) {
                $journalEntry = $payment->journalEntry;

                if ($journalEntry) {
                    $this->journalEntryService->reverse($journalEntry);
                }
            }

            $payment->delete();
        });
    }

    /**
     * Generate a client statement showing all transactions within a date range.
     *
     * @return array{opening_balance: string, transactions: array<int, array<string, mixed>>, closing_balance: string}
     */
    public function clientStatement(int $clientId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $fromDate = $fromDate ?? now()->startOfYear()->toDateString();
        $toDate = $toDate ?? now()->toDateString();

        // Calculate opening balance: sum of all invoice totals minus payments before the from date
        $invoicesBefore = Invoice::query()
            ->forClient($clientId)
            ->where('date', '<', $fromDate)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN total ELSE 0 END), 0) as invoice_total', [InvoiceType::Invoice->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN total ELSE 0 END), 0) as credit_note_total', [InvoiceType::CreditNote->value])
            ->first();

        $paymentsBefore = Payment::query()
            ->whereHas('invoice', fn ($q) => $q->forClient($clientId))
            ->where('date', '<', $fromDate)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $openingBalance = bcsub(
            bcsub((string) ($invoicesBefore->invoice_total ?? '0'), (string) ($invoicesBefore->credit_note_total ?? '0'), 2),
            (string) ($paymentsBefore ?? '0'),
            2
        );

        // Fetch transactions in the period
        $invoices = Invoice::query()
            ->forClient($clientId)
            ->whereBetween('date', [$fromDate, $toDate])
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->orderBy('date')
            ->orderBy('invoice_number')
            ->get();

        $payments = Payment::query()
            ->whereHas('invoice', fn ($q) => $q->forClient($clientId))
            ->whereBetween('date', [$fromDate, $toDate])
            ->orderBy('date')
            ->get();

        // Merge and sort transactions chronologically
        $transactions = [];

        foreach ($invoices as $invoice) {
            $isCredit = $invoice->type === InvoiceType::CreditNote;
            $transactions[] = [
                'date' => $invoice->date->toDateString(),
                'type' => $invoice->type->value,
                'reference' => $invoice->invoice_number,
                'description' => $isCredit ? "إشعار دائن {$invoice->invoice_number}" : "فاتورة {$invoice->invoice_number}",
                'debit' => $isCredit ? '0.00' : (string) $invoice->total,
                'credit' => $isCredit ? (string) $invoice->total : '0.00',
                'sort_key' => $invoice->date->toDateString().'_0_'.$invoice->invoice_number,
            ];
        }

        foreach ($payments as $payment) {
            $transactions[] = [
                'date' => $payment->date->toDateString(),
                'type' => 'payment',
                'reference' => $payment->reference ?? $payment->invoice->invoice_number,
                'description' => "دفعة - {$payment->method->labelAr()}",
                'debit' => '0.00',
                'credit' => (string) $payment->amount,
                'sort_key' => $payment->date->toDateString().'_1_'.($payment->reference ?? ''),
            ];
        }

        // Sort by date, then invoices before payments
        usort($transactions, fn ($a, $b) => strcmp($a['sort_key'], $b['sort_key']));

        // Calculate running balance
        $runningBalance = $openingBalance;

        foreach ($transactions as &$txn) {
            $runningBalance = bcadd(
                bcsub($runningBalance, $txn['credit'], 2),
                $txn['debit'],
                2
            );
            $txn['running_balance'] = $runningBalance;
            unset($txn['sort_key']);
        }
        unset($txn);

        return [
            'opening_balance' => $openingBalance,
            'transactions' => $transactions,
            'closing_balance' => $runningBalance,
        ];
    }

    /**
     * Generate an aging report for unpaid invoices.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, string>}
     */
    public function agingReport(?int $clientId = null): array
    {
        $today = now()->startOfDay();

        $invoices = Invoice::query()
            ->with(['client', 'payments'])
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue])
            ->when($clientId, fn ($q) => $q->forClient($clientId))
            ->get();

        $buckets = [];

        foreach ($invoices as $invoice) {
            $clientKey = $invoice->client_id;
            $clientName = $invoice->client?->name ?? 'Unknown';
            $balance = $invoice->balanceDue();

            if (bccomp((string) $balance, '0', 2) <= 0) {
                continue;
            }

            if (! isset($buckets[$clientKey])) {
                $buckets[$clientKey] = [
                    'client_id' => $clientKey,
                    'client_name' => $clientName,
                    'current' => '0.00',
                    'days_1_30' => '0.00',
                    'days_31_60' => '0.00',
                    'days_61_90' => '0.00',
                    'days_91_120' => '0.00',
                    'over_120' => '0.00',
                    'total' => '0.00',
                ];
            }

            $dueDate = $invoice->due_date;
            $daysOverdue = $dueDate ? (int) $today->diffInDays($dueDate, false) : 0;

            // daysOverdue is negative when overdue (today is past due_date)
            // We want positive number for overdue days
            $daysOverdue = -$daysOverdue;

            $bucket = match (true) {
                $daysOverdue <= 0 => 'current',
                $daysOverdue <= 30 => 'days_1_30',
                $daysOverdue <= 60 => 'days_31_60',
                $daysOverdue <= 90 => 'days_61_90',
                $daysOverdue <= 120 => 'days_91_120',
                default => 'over_120',
            };

            $buckets[$clientKey][$bucket] = bcadd($buckets[$clientKey][$bucket], (string) $balance, 2);
            $buckets[$clientKey]['total'] = bcadd($buckets[$clientKey]['total'], (string) $balance, 2);
        }

        // Calculate totals across all clients
        $totals = [
            'current' => '0.00',
            'days_1_30' => '0.00',
            'days_31_60' => '0.00',
            'days_61_90' => '0.00',
            'days_91_120' => '0.00',
            'over_120' => '0.00',
            'total' => '0.00',
        ];

        foreach ($buckets as $row) {
            foreach ($totals as $key => &$value) {
                $value = bcadd($value, $row[$key], 2);
            }
            unset($value);
        }

        // Sort rows by client name
        $rows = array_values($buckets);
        usort($rows, fn ($a, $b) => strcmp($a['client_name'], $b['client_name']));

        return [
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Resolve the GL account ID for a payment method.
     * Cash/Other => Cash on Hand (1111), all others => Bank (1112).
     *
     * @throws ValidationException
     */
    public function getPaymentAccountId(PaymentMethod $method, int $tenantId): int
    {
        $code = match ($method) {
            PaymentMethod::Cash, PaymentMethod::Other => config('accounting.default_accounts.cash'),
            PaymentMethod::BankTransfer, PaymentMethod::Check, PaymentMethod::CreditCard, PaymentMethod::MobileWallet => config('accounting.default_accounts.bank'),
        };

        return $this->glPostingService->resolveAccount($code, $tenantId);
    }
}
