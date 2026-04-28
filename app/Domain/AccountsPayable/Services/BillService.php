<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Services;

use App\Domain\Accounting\Services\GLPostingService;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\AccountsPayable\Enums\BillStatus;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\BillLine;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly GLPostingService $glPostingService,
    ) {}

    /**
     * List bills with search, filter, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        // Whitelist sortable columns so the SPA's sort_by can't reach an
        // unindexed column. Default to date desc, matching the original
        // bill-recency ordering.
        $allowedSorts = ['bill_number', 'date', 'due_date', 'total', 'amount_paid', 'created_at'];
        $sortBy = in_array($filters['sort_by'] ?? null, $allowedSorts, true)
            ? $filters['sort_by']
            : 'date';
        $sortDir = (($filters['sort_dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

        // Coerce status — silently ignore invalid values rather than 500ing.
        $status = null;
        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = $filters['status'] instanceof BillStatus
                ? $filters['status']
                : BillStatus::tryFrom((string) $filters['status']);
        }

        return Bill::query()
            ->with(['vendor'])
            ->withCount('lines')
            ->when(isset($filters['search']), fn ($q) => $q->search($filters['search']))
            ->when($status !== null, fn ($q) => $q->ofStatus($status))
            ->when(isset($filters['vendor_id']), fn ($q) => $q->forVendor((int) $filters['vendor_id']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']))
            ->when(isset($filters['due_from']), fn ($q) => $q->where('due_date', '>=', $filters['due_from']))
            ->when(isset($filters['due_to']), fn ($q) => $q->where('due_date', '<=', $filters['due_to']))
            ->orderBy($sortBy, $sortDir)
            ->orderBy('bill_number', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new bill with lines inside a transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Bill
    {
        return DB::transaction(function () use ($data): Bill {
            $billNumber = $this->generateBillNumber();

            $bill = Bill::query()->create([
                'vendor_id' => $data['vendor_id'],
                'bill_number' => $billNumber,
                'date' => $data['date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => BillStatus::Draft,
                'currency' => $data['currency'] ?? 'EGP',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $this->createLines($bill, $data['lines'] ?? []);
            $this->recalculateTotals($bill);

            return $bill->load(['vendor', 'lines']);
        });
    }

    /**
     * Update a draft bill with new data and lines.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Bill $bill, array $data): Bill
    {
        if (! $bill->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft bills can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($bill, $data): Bill {
            $bill->update([
                'vendor_id' => $data['vendor_id'] ?? $bill->vendor_id,
                'date' => $data['date'] ?? $bill->date,
                'due_date' => $data['due_date'] ?? $bill->due_date,
                'currency' => $data['currency'] ?? $bill->currency,
                'notes' => $data['notes'] ?? $bill->notes,
            ]);

            if (isset($data['lines'])) {
                $bill->lines()->delete();
                $this->createLines($bill, $data['lines']);
            }

            $this->recalculateTotals($bill);

            return $bill->refresh()->load(['vendor', 'lines']);
        });
    }

    /**
     * Soft-delete a draft bill.
     *
     * @throws ValidationException
     */
    public function delete(Bill $bill): void
    {
        if (! $bill->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft bills can be deleted.'],
            ]);
        }

        $bill->delete();
    }

    /**
     * Approve a draft bill and post to the General Ledger.
     *
     * @throws ValidationException
     */
    public function approve(Bill $bill): Bill
    {
        if (! $bill->status->canApprove()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft bills can be approved.'],
            ]);
        }

        return DB::transaction(function () use ($bill): Bill {
            $bill->load('lines');
            $tenantId = (int) app('tenant.id');
            $currency = $bill->currency ?? 'EGP';

            // Resolve GL account IDs
            $apAccountId = $this->glPostingService->resolveAccount(
                config('accounting.default_accounts.accounts_payable'),
                $tenantId
            );

            $vatInputAccountId = $this->glPostingService->resolveAccount(
                config('accounting.default_accounts.vat_input'),
                $tenantId
            );

            // Build journal entry lines
            $jeLines = [];

            // DEBIT: Expense accounts from each line's account_id, grouped by account
            $expenseByAccount = [];
            foreach ($bill->lines as $line) {
                $accountId = $line->account_id;

                if (! $accountId) {
                    throw ValidationException::withMessages([
                        'lines' => ['All bill lines must have an expense account assigned before approval.'],
                    ]);
                }

                $expenseByAccount[$accountId] = bcadd(
                    $expenseByAccount[$accountId] ?? '0.00',
                    (string) $line->line_total,
                    2
                );
            }

            foreach ($expenseByAccount as $accountId => $amount) {
                $jeLines[] = [
                    'account_id' => $accountId,
                    'debit' => Money::of($amount),
                    'credit' => Money::zero(),
                    'currency' => $currency,
                    'description' => "فاتورة مشتريات رقم {$bill->bill_number}",
                ];
            }

            // DEBIT: VAT Input for total VAT
            if (bccomp((string) $bill->vat_amount, '0.00', 2) > 0) {
                $jeLines[] = [
                    'account_id' => $vatInputAccountId,
                    'debit' => Money::of($bill->vat_amount),
                    'credit' => Money::zero(),
                    'currency' => $currency,
                    'description' => "ضريبة القيمة المضافة - فاتورة مشتريات رقم {$bill->bill_number}",
                ];
            }

            // CREDIT: Accounts Payable for the bill total
            $jeLines[] = [
                'account_id' => $apAccountId,
                'debit' => Money::zero(),
                'credit' => Money::of($bill->total),
                'currency' => $currency,
                'description' => "فاتورة مشتريات رقم {$bill->bill_number}",
            ];

            // CREDIT: WHT Payable for total WHT
            if (bccomp((string) $bill->wht_amount, '0.00', 2) > 0) {
                // Determine the WHT account — use the services WHT account as default
                $whtAccountId = $this->glPostingService->resolveAccount(
                    config('accounting.default_accounts.wht_services'),
                    $tenantId
                );

                $jeLines[] = [
                    'account_id' => $whtAccountId,
                    'debit' => Money::zero(),
                    'credit' => Money::of($bill->wht_amount),
                    'currency' => $currency,
                    'description' => "ضريبة خصم من المنبع - فاتورة مشتريات رقم {$bill->bill_number}",
                ];
            }

            // Create and post the journal entry
            $journalEntry = $this->glPostingService->post([
                'date' => $bill->date->toDateString(),
                'description' => "فاتورة مشتريات رقم {$bill->bill_number}",
                'reference' => $bill->bill_number,
                'lines' => $jeLines,
            ]);

            $bill->update([
                'status' => BillStatus::Approved,
                'journal_entry_id' => $journalEntry->id,
            ]);

            return $bill->refresh()->load('journalEntry');
        });
    }

    /**
     * Cancel a bill. Reverses journal entry if one exists.
     *
     * @throws ValidationException
     */
    public function cancel(Bill $bill): Bill
    {
        if (! $bill->status->canCancel()) {
            throw ValidationException::withMessages([
                'status' => ['This bill cannot be cancelled in its current status.'],
            ]);
        }

        return DB::transaction(function () use ($bill): Bill {
            // Reverse the journal entry if one was posted
            if ($bill->journal_entry_id) {
                $journalEntry = $bill->journalEntry;

                if ($journalEntry) {
                    $this->journalEntryService->reverse($journalEntry);
                }
            }

            $bill->update([
                'status' => BillStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
            ]);

            return $bill->refresh();
        });
    }

    /**
     * Recalculate bill totals from its lines using bcmath.
     */
    public function recalculateTotals(Bill $bill): Bill
    {
        $bill->load('lines');

        $subtotal = '0.00';
        $vatAmount = '0.00';
        $whtAmount = '0.00';

        foreach ($bill->lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_total, 2);
            $vatAmount = bcadd($vatAmount, (string) $line->vat_amount, 2);
            $whtAmount = bcadd($whtAmount, (string) $line->wht_amount, 2);
        }

        // total = subtotal + VAT - WHT
        $total = bcsub(bcadd($subtotal, $vatAmount, 2), $whtAmount, 2);

        $bill->update([
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'wht_amount' => $whtAmount,
            'total' => $total,
        ]);

        return $bill->refresh();
    }

    /**
     * Generate the next sequential bill number using max+1 with a lock.
     */
    private function generateBillNumber(): string
    {
        $lastNumber = Bill::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', app('tenant.id'))
            ->lockForUpdate()
            ->max(DB::raw('CAST(SUBSTRING(bill_number FROM 6) AS INTEGER)'));

        $nextNumber = ($lastNumber ?? 0) + 1;

        return 'BILL-'.str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Create bill line records and calculate their totals.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createLines(Bill $bill, array $lines): void
    {
        foreach ($lines as $index => $lineData) {
            $line = new BillLine([
                'bill_id' => $bill->id,
                'description' => $lineData['description'] ?? '',
                'quantity' => $lineData['quantity'] ?? 1,
                'unit_price' => $lineData['unit_price'] ?? 0,
                'discount_percent' => $lineData['discount_percent'] ?? 0,
                'vat_rate' => $lineData['vat_rate'] ?? (float) config('tax.vat_rate', '14.00'),
                'wht_rate' => $lineData['wht_rate'] ?? 0,
                'sort_order' => $lineData['sort_order'] ?? $index,
                'account_id' => $lineData['account_id'] ?? null,
                // Optional FK back to the saved vendor product. Used by the
                // BillLine observer to bump `last_used_at`; the snapshot
                // fields above remain the source of truth for print/post.
                'vendor_product_id' => $lineData['vendor_product_id'] ?? null,
            ]);

            $line->calculateTotals();

            $bill->lines()->save($line);
        }
    }
}
