<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Services\BillPaymentService;
use App\Domain\Billing\Models\AutoApprovalRule;
use App\Domain\Billing\Models\PaymentSchedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentWorkflowService
{
    public function __construct(
        private readonly BillPaymentService $billPaymentService,
    ) {}

    /**
     * Create a payment schedule for a bill.
     * Auto-calculate early discount if within deadline.
     */
    public function schedulePayment(int $billId, string $date, ?string $method = null): PaymentSchedule
    {
        $bill = Bill::query()->findOrFail($billId);

        $amount = (string) $bill->balanceDue();

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'bill_id' => ['This bill has no outstanding balance.'],
            ]);
        }

        $earlyDiscountPercent = '0.00';
        $earlyDiscountDeadline = null;
        $earlyDiscountAmount = '0.00';

        // Check for early payment discount on the bill
        if ($bill->early_discount_percent && $bill->early_discount_deadline) {
            $earlyDiscountDeadline = $bill->early_discount_deadline;
            $earlyDiscountPercent = (string) $bill->early_discount_percent;

            // If scheduled date is within the early discount deadline, calculate discount
            if ($date <= $earlyDiscountDeadline->toDateString()) {
                $earlyDiscountAmount = bcdiv(
                    bcmul($amount, $earlyDiscountPercent, 4),
                    '100',
                    2,
                );
            }
        }

        return PaymentSchedule::query()->create([
            'tenant_id' => app('tenant.id'),
            'bill_id' => $billId,
            'scheduled_date' => $date,
            'amount' => $amount,
            'status' => 'pending',
            'payment_method' => $method,
            'early_discount_percent' => $earlyDiscountPercent,
            'early_discount_deadline' => $earlyDiscountDeadline,
            'early_discount_amount' => $earlyDiscountAmount,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Schedule multiple bills at once.
     *
     * @param  array<int>  $billIds
     * @return Collection<int, PaymentSchedule>
     */
    public function scheduleBulk(array $billIds, string $date): Collection
    {
        $schedules = collect();

        foreach ($billIds as $billId) {
            $schedules->push($this->schedulePayment($billId, $date));
        }

        return $schedules;
    }

    /**
     * Mark a payment schedule as approved.
     */
    public function approveSchedule(PaymentSchedule $schedule): PaymentSchedule
    {
        if (! $schedule->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending schedules can be approved.'],
            ]);
        }

        $schedule->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
        ]);

        return $schedule->refresh();
    }

    /**
     * Process all approved schedules where date <= today.
     * Create BillPayment for each. Wrap in transaction.
     */
    public function processScheduled(): int
    {
        $schedules = PaymentSchedule::query()
            ->ofStatus('approved')
            ->scheduledBefore(now()->toDateString())
            ->with('bill')
            ->get();

        $processed = 0;

        foreach ($schedules as $schedule) {
            DB::transaction(function () use ($schedule, &$processed): void {
                if (! $schedule->bill) {
                    $schedule->update(['status' => 'skipped']);

                    return;
                }

                $paymentAmount = (string) $schedule->amount;

                // Apply early discount if applicable
                if (bccomp((string) $schedule->early_discount_amount, '0', 2) > 0
                    && $schedule->early_discount_deadline
                    && now()->toDateString() <= $schedule->early_discount_deadline->toDateString()
                ) {
                    $paymentAmount = bcsub($paymentAmount, (string) $schedule->early_discount_amount, 2);
                }

                $method = $schedule->payment_method ?? 'bank_transfer';

                $this->billPaymentService->record($schedule->bill, [
                    'amount' => $paymentAmount,
                    'date' => $schedule->scheduled_date->toDateString(),
                    'method' => $method,
                    'reference' => "SCHED-{$schedule->id}",
                    'notes' => $schedule->notes,
                ]);

                $schedule->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);

                $processed++;
            });
        }

        return $processed;
    }

    /**
     * Filter scheduled payments by status, date range, vendor.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listScheduled(array $filters = []): LengthAwarePaginator
    {
        return PaymentSchedule::query()
            ->with(['bill.vendor', 'invoice.client', 'approvedByUser', 'createdByUser'])
            ->when(isset($filters['status']), fn ($q) => $q->ofStatus($filters['status']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('scheduled_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('scheduled_date', '<=', $filters['date_to']))
            ->when(isset($filters['vendor_id']), fn ($q) => $q->whereHas('bill', fn ($bq) => $bq->where('vendor_id', $filters['vendor_id'])))
            ->when(isset($filters['bill_id']), fn ($q) => $q->forBill((int) $filters['bill_id']))
            ->orderBy('scheduled_date')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Find bills with early discount deadlines approaching.
     * Calculate potential savings.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function earlyDiscountOpportunities(): Collection
    {
        $bills = Bill::query()
            ->whereNotNull('early_discount_deadline')
            ->where('early_discount_deadline', '>=', now()->toDateString())
            ->where('early_discount_deadline', '<=', now()->addDays(14)->toDateString())
            ->whereColumn('amount_paid', '<', 'total')
            ->with('vendor')
            ->get();

        return $bills->map(function (Bill $bill): array {
            $balanceDue = (string) $bill->balanceDue();
            $discountPercent = (string) ($bill->early_discount_percent ?? '0');
            $savings = bcdiv(bcmul($balanceDue, $discountPercent, 4), '100', 2);

            return [
                'bill_id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'vendor' => $bill->vendor?->name,
                'balance_due' => $balanceDue,
                'discount_percent' => $discountPercent,
                'discount_deadline' => $bill->early_discount_deadline?->toDateString(),
                'potential_savings' => $savings,
                'days_remaining' => (int) now()->diffInDays($bill->early_discount_deadline, false),
            ];
        });
    }

    /**
     * Create an auto-approval rule.
     *
     * @param  array<string, mixed>  $data
     */
    public function createAutoRule(array $data): AutoApprovalRule
    {
        return AutoApprovalRule::query()->create([
            'tenant_id' => app('tenant.id'),
            'entity_type' => $data['entity_type'],
            'condition_field' => $data['condition_field'],
            'operator' => $data['operator'],
            'condition_value' => $data['condition_value'],
            'auto_action' => $data['auto_action'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Check if entity matches any auto-approval rule.
     * If yes, auto-approve.
     */
    public function evaluateAutoRules(string $entityType, Model $entity): bool
    {
        $rules = AutoApprovalRule::query()
            ->active()
            ->forEntityType($entityType)
            ->get();

        foreach ($rules as $rule) {
            $fieldValue = $entity->{$rule->condition_field} ?? null;

            if ($fieldValue === null) {
                continue;
            }

            if ($rule->matches($fieldValue)) {
                return true;
            }
        }

        return false;
    }
}
