<?php

declare(strict_types=1);

namespace App\Domain\Collection\Services;

use App\Domain\Accounting\Services\GLPostingService;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Collection\Enums\CollectionActionType;
use App\Domain\Collection\Enums\CollectionOutcome;
use App\Domain\Collection\Enums\CollectionStatus;
use App\Domain\Collection\Models\CollectionAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly GLPostingService $glPostingService,
    ) {}

    /**
     * Log a collection action against an invoice.
     *
     * Creates a CollectionAction record, updates the invoice's collection_status
     * and last_collection_date. Tracks payment commitments when applicable.
     *
     * @param  array<string, mixed>  $data
     */
    public function logAction(array $data): CollectionAction
    {
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);

        $actionType = $data['action_type'] instanceof CollectionActionType
            ? $data['action_type']
            : CollectionActionType::from($data['action_type']);

        $outcome = isset($data['outcome'])
            ? ($data['outcome'] instanceof CollectionOutcome
                ? $data['outcome']
                : CollectionOutcome::from($data['outcome']))
            : null;

        $action = CollectionAction::query()->create([
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'action_type' => $actionType,
            'outcome' => $outcome,
            'notes' => $data['notes'] ?? null,
            'action_date' => $data['action_date'] ?? now()->toDateString(),
            'commitment_date' => $data['commitment_date'] ?? null,
            'commitment_amount' => $data['commitment_amount'] ?? null,
            'created_by' => Auth::id(),
        ]);

        // Update invoice collection tracking
        $updateData = [
            'last_collection_date' => $action->action_date,
        ];

        if ($outcome === CollectionOutcome::PaymentCommitment) {
            $updateData['collection_status'] = CollectionStatus::Committed;
        } elseif ($invoice->collection_status === null || $invoice->collection_status === CollectionStatus::None) {
            $updateData['collection_status'] = CollectionStatus::InProgress;
        }

        $invoice->update($updateData);

        return $action->load(['invoice.client', 'createdByUser']);
    }

    /**
     * Paginated list of collection actions with eager-loaded relationships.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listActions(array $filters = []): LengthAwarePaginator
    {
        return CollectionAction::query()
            ->with(['invoice.client', 'createdByUser'])
            ->when(
                isset($filters['client_id']),
                fn ($q) => $q->forClient((int) $filters['client_id'])
            )
            ->when(
                isset($filters['invoice_id']),
                fn ($q) => $q->forInvoice((int) $filters['invoice_id'])
            )
            ->when(
                isset($filters['action_type']),
                fn ($q) => $q->ofType(
                    $filters['action_type'] instanceof CollectionActionType
                        ? $filters['action_type']
                        : CollectionActionType::from($filters['action_type'])
                )
            )
            ->when(
                isset($filters['outcome']),
                fn ($q) => $q->ofOutcome(
                    $filters['outcome'] instanceof CollectionOutcome
                        ? $filters['outcome']
                        : CollectionOutcome::from($filters['outcome'])
                )
            )
            ->when(isset($filters['date_from']), fn ($q) => $q->where('action_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('action_date', '<=', $filters['date_to']))
            ->orderBy('action_date', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Collection dashboard overview data.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters = []): array
    {
        $today = now()->toDateString();

        // Total overdue amount
        $overdueInvoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue])
            ->where('due_date', '<', $today)
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->get();

        $totalOverdue = '0.00';
        foreach ($overdueInvoices as $invoice) {
            $balance = bcsub((string) $invoice->total, (string) $invoice->amount_paid, 2);
            if (bccomp($balance, '0', 2) > 0) {
                $totalOverdue = bcadd($totalOverdue, $balance, 2);
            }
        }

        // Count by aging bucket
        $agingBuckets = [
            '1_30' => 0,
            '31_60' => 0,
            '61_90' => 0,
            '90_plus' => 0,
        ];

        $todayCarbon = now()->startOfDay();
        foreach ($overdueInvoices as $invoice) {
            $daysOverdue = $invoice->due_date
                ? (int) $todayCarbon->diffInDays($invoice->due_date, false) * -1
                : 0;

            if ($daysOverdue <= 0) {
                continue;
            }

            match (true) {
                $daysOverdue <= 30 => $agingBuckets['1_30']++,
                $daysOverdue <= 60 => $agingBuckets['31_60']++,
                $daysOverdue <= 90 => $agingBuckets['61_90']++,
                default => $agingBuckets['90_plus']++,
            };
        }

        // Recent collection actions
        $recentActions = CollectionAction::query()
            ->with(['invoice.client', 'createdByUser'])
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->orderBy('action_date', 'desc')
            ->limit(10)
            ->get();

        // Upcoming commitments
        $upcomingCommitments = CollectionAction::query()
            ->with(['invoice.client'])
            ->upcomingCommitments()
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->orderBy('commitment_date', 'asc')
            ->limit(10)
            ->get();

        // DSO = (AR balance / total credit sales) * days in period
        $periodDays = (int) ($filters['period_days'] ?? 30);
        $periodStart = now()->subDays($periodDays)->toDateString();

        $arBalance = $totalOverdue;

        $totalCreditSales = Invoice::query()
            ->whereIn('status', [
                InvoiceStatus::Sent,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Overdue,
                InvoiceStatus::Paid,
            ])
            ->where('date', '>=', $periodStart)
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->value('total');

        $totalCreditSalesStr = (string) ($totalCreditSales ?? '0');
        $dso = '0.00';
        if (bccomp($totalCreditSalesStr, '0', 2) > 0) {
            $dso = bcdiv(
                bcmul($arBalance, (string) $periodDays, 2),
                $totalCreditSalesStr,
                2
            );
        }

        return [
            'total_overdue' => $totalOverdue,
            'aging_buckets' => $agingBuckets,
            'recent_actions' => $recentActions,
            'upcoming_commitments' => $upcomingCommitments,
            'dso' => $dso,
        ];
    }

    /**
     * Write off an invoice amount.
     *
     * Posts a GL journal (DEBIT bad debt expense, CREDIT AR), updates the invoice
     * write-off fields, and logs a collection action.
     *
     * @throws ValidationException
     */
    public function writeOff(Invoice $invoice, string $amount, ?string $reason = null): CollectionAction
    {
        $balanceDue = bcsub((string) $invoice->total, (string) $invoice->amount_paid, 2);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Write-off amount must be greater than zero.'],
            ]);
        }

        if (bccomp($amount, $balanceDue, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => ["Write-off amount ({$amount}) exceeds the balance due ({$balanceDue})."],
            ]);
        }

        return DB::transaction(function () use ($invoice, $amount, $reason): CollectionAction {
            $tenantId = (int) app('tenant.id');

            // Resolve GL accounts
            $badDebtAccountId = $this->glPostingService->resolveAccount(
                config('accounting.default_accounts.bad_debt_expense'),
                $tenantId
            );
            $arAccountId = $this->glPostingService->resolveAccount(
                config('accounting.default_accounts.accounts_receivable'),
                $tenantId
            );

            // Post GL journal entry
            $journalEntry = $this->glPostingService->post([
                'date' => now()->toDateString(),
                'description' => "شطب ديون معدومة - فاتورة رقم {$invoice->invoice_number}",
                'reference' => $invoice->invoice_number,
                'lines' => [
                    [
                        'account_id' => $badDebtAccountId,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => "شطب ديون معدومة - فاتورة رقم {$invoice->invoice_number}",
                    ],
                    [
                        'account_id' => $arAccountId,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => "شطب ديون معدومة - فاتورة رقم {$invoice->invoice_number}",
                    ],
                ],
            ]);

            // Update invoice write-off fields
            $currentWriteOff = (string) ($invoice->write_off_amount ?? '0');
            $newWriteOff = bcadd($currentWriteOff, $amount, 2);

            $invoice->update([
                'write_off_amount' => $newWriteOff,
                'write_off_journal_id' => $journalEntry->id,
                'collection_status' => CollectionStatus::WrittenOff,
            ]);

            // Log the collection action
            return $this->logAction([
                'invoice_id' => $invoice->id,
                'action_type' => CollectionActionType::WriteOff,
                'outcome' => CollectionOutcome::WrittenOff,
                'notes' => $reason ?? "شطب مبلغ {$amount} من فاتورة رقم {$invoice->invoice_number}",
                'action_date' => now()->toDateString(),
            ]);
        });
    }

    /**
     * Escalate an overdue invoice.
     *
     * Updates collection_status to escalated and logs an escalation action.
     */
    public function escalate(Invoice $invoice, string $reason): CollectionAction
    {
        $invoice->update([
            'collection_status' => CollectionStatus::Escalated,
        ]);

        return $this->logAction([
            'invoice_id' => $invoice->id,
            'action_type' => CollectionActionType::Escalation,
            'outcome' => CollectionOutcome::Escalated,
            'notes' => $reason,
            'action_date' => now()->toDateString(),
        ]);
    }

    /**
     * Per-client collection summary.
     *
     * @return array<string, mixed>
     */
    public function clientCollectionSummary(int $clientId): array
    {
        $today = now()->toDateString();
        $todayCarbon = now()->startOfDay();

        // Overdue invoices for this client
        $overdueInvoices = Invoice::query()
            ->forClient($clientId)
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue])
            ->where('due_date', '<', $today)
            ->get();

        $totalOverdue = '0.00';
        $agingBreakdown = [
            '1_30' => '0.00',
            '31_60' => '0.00',
            '61_90' => '0.00',
            '90_plus' => '0.00',
        ];

        foreach ($overdueInvoices as $invoice) {
            $balance = bcsub((string) $invoice->total, (string) $invoice->amount_paid, 2);
            if (bccomp($balance, '0', 2) <= 0) {
                continue;
            }

            $totalOverdue = bcadd($totalOverdue, $balance, 2);

            $daysOverdue = $invoice->due_date
                ? (int) $todayCarbon->diffInDays($invoice->due_date, false) * -1
                : 0;

            if ($daysOverdue <= 0) {
                continue;
            }

            $bucket = match (true) {
                $daysOverdue <= 30 => '1_30',
                $daysOverdue <= 60 => '31_60',
                $daysOverdue <= 90 => '61_90',
                default => '90_plus',
            };

            $agingBreakdown[$bucket] = bcadd($agingBreakdown[$bucket], $balance, 2);
        }

        // Action history
        $actionHistory = CollectionAction::query()
            ->with(['invoice', 'createdByUser'])
            ->forClient($clientId)
            ->orderBy('action_date', 'desc')
            ->limit(50)
            ->get();

        // Upcoming commitments
        $commitments = CollectionAction::query()
            ->with(['invoice'])
            ->forClient($clientId)
            ->upcomingCommitments()
            ->orderBy('commitment_date', 'asc')
            ->get();

        return [
            'total_overdue' => $totalOverdue,
            'aging_breakdown' => $agingBreakdown,
            'action_history' => $actionHistory,
            'commitments' => $commitments,
        ];
    }

    /**
     * Collection effectiveness metrics.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function effectivenessReport(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();

        // Total overdue at start of period: invoices that were overdue as of date_from
        $overdueAtStart = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue, InvoiceStatus::Paid])
            ->where('due_date', '<', $dateFrom)
            ->where('date', '<', $dateFrom)
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->get();

        $totalOverdueAtStart = '0.00';
        foreach ($overdueAtStart as $invoice) {
            $balance = bcsub((string) $invoice->total, (string) $invoice->amount_paid, 2);
            if (bccomp($balance, '0', 2) > 0) {
                $totalOverdueAtStart = bcadd($totalOverdueAtStart, $balance, 2);
            }
        }

        // Collected amount during period
        $collectedAmount = Payment::query()
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->when(
                isset($filters['client_id']),
                fn ($q) => $q->whereHas('invoice', fn ($iq) => $iq->forClient((int) $filters['client_id']))
            )
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $collectedAmountStr = (string) ($collectedAmount ?? '0');

        // Recovery rate
        $recoveryRate = '0.00';
        if (bccomp($totalOverdueAtStart, '0', 2) > 0) {
            $recoveryRate = bcmul(
                bcdiv($collectedAmountStr, $totalOverdueAtStart, 4),
                '100',
                2
            );
        }

        // Average collection period (average days between invoice due_date and payment date)
        $paidInvoicesWithDays = DB::table('payments')
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->whereBetween('payments.date', [$dateFrom, $dateTo])
            ->whereNotNull('invoices.due_date')
            ->when(isset($filters['client_id']), fn ($q) => $q->where('invoices.client_id', $filters['client_id']))
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (payments.date::timestamp - invoices.due_date::timestamp)) / 86400) as avg_days')
            ->value('avg_days');

        $avgCollectionPeriod = $paidInvoicesWithDays !== null
            ? number_format((float) $paidInvoicesWithDays, 1)
            : '0.0';

        // Actions per invoice
        $actionsInPeriod = CollectionAction::query()
            ->whereBetween('action_date', [$dateFrom, $dateTo])
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->count();

        $invoicesWithActions = CollectionAction::query()
            ->whereBetween('action_date', [$dateFrom, $dateTo])
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->distinct('invoice_id')
            ->count('invoice_id');

        $actionsPerInvoice = $invoicesWithActions > 0
            ? bcdiv((string) $actionsInPeriod, (string) $invoicesWithActions, 2)
            : '0.00';

        // Success rate by action type
        $actionTypeCounts = CollectionAction::query()
            ->whereBetween('action_date', [$dateFrom, $dateTo])
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->select('action_type')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN outcome IN (?, ?, ?) THEN 1 ELSE 0 END) as successful', [
                CollectionOutcome::PaymentCommitment->value,
                CollectionOutcome::PartialPayment->value,
                CollectionOutcome::PaymentReceived->value,
            ])
            ->groupBy('action_type')
            ->get();

        $successRateByType = [];
        foreach ($actionTypeCounts as $row) {
            $rate = (int) $row->total > 0
                ? bcmul(bcdiv((string) $row->successful, (string) $row->total, 4), '100', 2)
                : '0.00';

            $successRateByType[$row->action_type] = [
                'total' => (int) $row->total,
                'successful' => (int) $row->successful,
                'rate' => $rate,
            ];
        }

        return [
            'recovery_rate' => $recoveryRate,
            'total_overdue_at_start' => $totalOverdueAtStart,
            'collected_amount' => $collectedAmountStr,
            'avg_collection_period' => $avgCollectionPeriod,
            'actions_per_invoice' => $actionsPerInvoice,
            'success_rate_by_type' => $successRateByType,
        ];
    }
}
