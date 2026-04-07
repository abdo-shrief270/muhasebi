<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\AccountsPayable\Enums\BillStatus;
use App\Domain\AccountsPayable\Enums\PaymentMethod;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\BillPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillPaymentService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * List bill payments with filters and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return BillPayment::query()
            ->with(['bill.vendor'])
            ->when(isset($filters['bill_id']), fn ($q) => $q->forBill((int) $filters['bill_id']))
            ->when(isset($filters['vendor_id']), fn ($q) => $q->forVendor((int) $filters['vendor_id']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('payment_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('payment_date', '<=', $filters['date_to']))
            ->when(
                isset($filters['method']),
                fn ($q) => $q->ofMethod(
                    $filters['method'] instanceof PaymentMethod
                        ? $filters['method']
                        : PaymentMethod::from($filters['method'])
                )
            )
            ->orderBy('payment_date', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Record a payment against a bill.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function record(Bill $bill, array $data): BillPayment
    {
        if (! $bill->status->canPay()) {
            throw ValidationException::withMessages([
                'status' => ['Payments can only be recorded for approved, partially paid, or overdue bills.'],
            ]);
        }

        $amount = (string) $data['amount'];

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount must be greater than zero.'],
            ]);
        }

        $balanceDue = (string) $bill->balanceDue();

        if (bccomp($amount, $balanceDue, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => ["Payment amount ({$amount}) exceeds the balance due ({$balanceDue})."],
            ]);
        }

        return DB::transaction(function () use ($bill, $data, $amount): BillPayment {
            $method = $data['method'] instanceof PaymentMethod
                ? $data['method']
                : PaymentMethod::from($data['method']);

            $tenantId = (int) app('tenant.id');

            // Resolve GL accounts
            $apAccountId = $this->resolveAccountByCode(
                config('accounting.default_accounts.accounts_payable'),
                $tenantId
            );
            $paymentAccountId = $this->resolveAccountByCode(
                $method->defaultAccountCode(),
                $tenantId
            );

            // Create the journal entry: DEBIT AP, CREDIT payment account
            $journalEntry = $this->journalEntryService->create([
                'date' => $data['date'] ?? now()->toDateString(),
                'description' => "سداد دفعة - فاتورة مشتريات رقم {$bill->bill_number}",
                'reference' => $data['reference'] ?? $bill->bill_number,
                'lines' => [
                    [
                        'account_id' => $apAccountId,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => "سداد دفعة - فاتورة مشتريات رقم {$bill->bill_number}",
                    ],
                    [
                        'account_id' => $paymentAccountId,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => "سداد دفعة - فاتورة مشتريات رقم {$bill->bill_number}",
                    ],
                ],
            ]);

            $this->journalEntryService->post($journalEntry);

            // Create payment record
            $payment = BillPayment::query()->create([
                'tenant_id' => $tenantId,
                'bill_id' => $bill->id,
                'vendor_id' => $bill->vendor_id,
                'amount' => $amount,
                'payment_date' => $data['date'] ?? now()->toDateString(),
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'journal_entry_id' => $journalEntry->id,
                'created_by' => Auth::id(),
            ]);

            // Update bill amount_paid and status
            $newAmountPaid = bcadd((string) $bill->amount_paid, $amount, 2);
            $bill->update(['amount_paid' => $newAmountPaid]);
            $bill->refresh();

            if ($bill->isFullyPaid()) {
                $bill->update(['status' => BillStatus::Paid]);
            } else {
                $bill->update(['status' => BillStatus::PartiallyPaid]);
            }

            return $payment->load('bill.vendor');
        });
    }

    /**
     * Void (reverse) a payment.
     *
     * @throws ValidationException
     */
    public function void(BillPayment $payment): void
    {
        $payment->load('bill');
        $bill = $payment->bill;

        if ($bill->status === BillStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['Payments on cancelled bills cannot be voided.'],
            ]);
        }

        DB::transaction(function () use ($payment, $bill): void {
            // Reduce bill amount_paid
            $newAmountPaid = bcsub((string) $bill->amount_paid, (string) $payment->amount, 2);

            if (bccomp($newAmountPaid, '0', 2) < 0) {
                $newAmountPaid = '0.00';
            }

            $bill->update(['amount_paid' => $newAmountPaid]);
            $bill->refresh();

            // Recalculate bill status
            if (bccomp((string) $bill->amount_paid, '0', 2) === 0) {
                $bill->update(['status' => BillStatus::Approved]);
            } elseif (! $bill->isFullyPaid()) {
                $bill->update(['status' => BillStatus::PartiallyPaid]);
            }

            // Reverse the journal entry if present
            if ($payment->journal_entry_id) {
                $journalEntry = $payment->journalEntry;

                if ($journalEntry) {
                    $this->journalEntryService->reverse($journalEntry);
                }
            }

            // Soft delete the payment
            $payment->delete();
        });
    }

    /**
     * Resolve an account ID by its code for a given tenant.
     *
     * @throws ValidationException
     */
    private function resolveAccountByCode(string $code, int $tenantId): int
    {
        $account = Account::query()
            ->forTenant($tenantId)
            ->where('code', $code)
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'account' => ["Required account with code '{$code}' not found. Please set up your chart of accounts."],
            ]);
        }

        return $account->id;
    }
}
