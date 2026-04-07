<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\ClientPortal\Enums\DisputeStatus;
use App\Domain\ClientPortal\Enums\InstallmentStatus;
use App\Domain\ClientPortal\Enums\PaymentPlanFrequency;
use App\Domain\ClientPortal\Enums\PaymentPlanStatus;
use App\Domain\ClientPortal\Models\InvoiceDispute;
use App\Domain\ClientPortal\Models\PaymentPlan;
use App\Domain\ClientPortal\Models\PaymentPlanInstallment;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ClientPortalEnhancedService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    // ──────────────────────────────────────
    // Disputes
    // ──────────────────────────────────────

    /**
     * Create an invoice dispute and notify the tenant admin.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDispute(int $clientId, array $data): InvoiceDispute
    {
        $dispute = InvoiceDispute::create([
            'tenant_id' => app('tenant.id'),
            'invoice_id' => $data['invoice_id'],
            'client_id' => $clientId,
            'subject' => $data['subject'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 'medium',
            'status' => DisputeStatus::Open,
        ]);

        // Notify tenant admin(s)
        $admins = User::where('tenant_id', app('tenant.id'))
            ->where('role', UserRole::Admin)
            ->pluck('id');

        foreach ($admins as $adminId) {
            $this->notificationService->send(
                userId: $adminId,
                type: NotificationType::InvoiceDispute,
                titleAr: 'نزاع جديد على فاتورة',
                titleEn: 'New Invoice Dispute',
                bodyAr: "تم فتح نزاع جديد: {$dispute->subject}",
                bodyEn: "A new dispute has been opened: {$dispute->subject}",
            );
        }

        return $dispute->load(['invoice', 'client']);
    }

    /**
     * List disputes for a client.
     */
    public function listDisputes(int $clientId): LengthAwarePaginator
    {
        return InvoiceDispute::forClient($clientId)
            ->with(['invoice'])
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    /**
     * Resolve or reject a dispute.
     */
    public function resolveDispute(InvoiceDispute $dispute, string $resolution, string $status): InvoiceDispute
    {
        $dispute->update([
            'resolution' => $resolution,
            'status' => $status,
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ]);

        return $dispute->fresh(['invoice', 'client', 'resolver']);
    }

    // ──────────────────────────────────────
    // Payment Plans
    // ──────────────────────────────────────

    /**
     * Create a payment plan with calculated installments using bcmath.
     */
    public function createPaymentPlan(int $invoiceId, int $installments, string $frequency): PaymentPlan
    {
        $invoice = Invoice::findOrFail($invoiceId);
        $totalAmount = bcsub((string) $invoice->total, (string) $invoice->amount_paid, 2);
        $installmentAmount = bcdiv($totalAmount, (string) $installments, 2);

        // Handle rounding remainder: last installment absorbs the difference
        $allocatedTotal = bcmul($installmentAmount, (string) $installments, 2);
        $remainder = bcsub($totalAmount, $allocatedTotal, 2);

        $frequencyEnum = PaymentPlanFrequency::from($frequency);
        $startDate = Carbon::today();

        $plan = PaymentPlan::create([
            'tenant_id' => app('tenant.id'),
            'invoice_id' => $invoiceId,
            'client_id' => $invoice->client_id,
            'total_amount' => $totalAmount,
            'installments_count' => $installments,
            'installment_amount' => $installmentAmount,
            'frequency' => $frequencyEnum,
            'start_date' => $startDate,
            'status' => PaymentPlanStatus::Active,
            'next_due_date' => $startDate->copy()->addDays($frequencyEnum->intervalDays()),
            'paid_installments' => 0,
            'remaining_amount' => $totalAmount,
            'created_by' => auth()->id(),
        ]);

        // Create installment records
        $dueDate = $startDate->copy();
        for ($i = 1; $i <= $installments; $i++) {
            $dueDate = $dueDate->addDays($frequencyEnum->intervalDays());
            $amount = $installmentAmount;

            // Add remainder to the last installment
            if ($i === $installments && bccomp($remainder, '0.00', 2) !== 0) {
                $amount = bcadd($installmentAmount, $remainder, 2);
            }

            PaymentPlanInstallment::create([
                'payment_plan_id' => $plan->id,
                'due_date' => $dueDate->copy(),
                'amount' => $amount,
                'status' => InstallmentStatus::Pending,
            ]);
        }

        return $plan->load('installments');
    }

    /**
     * List payment plans for a client.
     */
    public function listPaymentPlans(int $clientId): LengthAwarePaginator
    {
        return PaymentPlan::forClient($clientId)
            ->with(['invoice', 'installments'])
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    /**
     * Record an installment payment, advance the plan, and check for completion.
     *
     * @param  array<string, mixed>  $paymentData
     */
    public function recordInstallmentPayment(PaymentPlanInstallment $installment, array $paymentData): PaymentPlanInstallment
    {
        $installment->update([
            'status' => InstallmentStatus::Paid,
            'paid_at' => now(),
            'payment_id' => $paymentData['payment_id'] ?? null,
        ]);

        $plan = $installment->plan;
        $paidCount = $plan->installments()->where('status', InstallmentStatus::Paid)->count();
        $remainingAmount = bcsub(
            (string) $plan->total_amount,
            bcmul((string) $plan->installment_amount, (string) $paidCount, 2),
            2,
        );

        // Find next pending installment due date
        $nextInstallment = $plan->installments()
            ->where('status', InstallmentStatus::Pending)
            ->orderBy('due_date')
            ->first();

        $planUpdate = [
            'paid_installments' => $paidCount,
            'remaining_amount' => max(0, (float) $remainingAmount),
            'next_due_date' => $nextInstallment?->due_date,
        ];

        // Check if plan is completed
        if ($paidCount >= $plan->installments_count) {
            $planUpdate['status'] = PaymentPlanStatus::Completed;
            $planUpdate['remaining_amount'] = '0.00';
            $planUpdate['next_due_date'] = null;
        }

        $plan->update($planUpdate);

        return $installment->fresh(['plan']);
    }

    // ──────────────────────────────────────
    // Client Reports
    // ──────────────────────────────────────

    /**
     * Client-accessible summary: balance, aging, YTD payments, open disputes.
     *
     * @return array<string, mixed>
     */
    public function clientReports(int $clientId): array
    {
        $outstandingStatuses = [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid];

        // Outstanding balance
        $outstandingBalance = (float) Invoice::forClient($clientId)
            ->whereIn('status', $outstandingStatuses)
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as balance')
            ->value('balance');

        // Aging buckets
        $today = Carbon::today();
        $aging = [
            'current' => 0.0,
            '1_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            'over_90' => 0.0,
        ];

        $overdueInvoices = Invoice::forClient($clientId)
            ->whereIn('status', $outstandingStatuses)
            ->get(['due_date', 'total', 'amount_paid']);

        foreach ($overdueInvoices as $invoice) {
            $balance = (float) bcsub((string) $invoice->total, (string) $invoice->amount_paid, 2);
            $daysOverdue = $today->diffInDays($invoice->due_date, false);

            if ($daysOverdue >= 0) {
                $aging['current'] += $balance;
            } elseif ($daysOverdue >= -30) {
                $aging['1_30'] += $balance;
            } elseif ($daysOverdue >= -60) {
                $aging['31_60'] += $balance;
            } elseif ($daysOverdue >= -90) {
                $aging['61_90'] += $balance;
            } else {
                $aging['over_90'] += $balance;
            }
        }

        // YTD payments
        $ytdPayments = (float) Invoice::forClient($clientId)
            ->whereYear('date', $today->year)
            ->selectRaw('COALESCE(SUM(amount_paid), 0) as total_paid')
            ->value('total_paid');

        // Open disputes count
        $openDisputes = InvoiceDispute::forClient($clientId)
            ->where('status', DisputeStatus::Open)
            ->count();

        // Active payment plans
        $activePaymentPlans = PaymentPlan::forClient($clientId)
            ->where('status', PaymentPlanStatus::Active)
            ->count();

        return [
            'outstanding_balance' => $outstandingBalance,
            'aging' => $aging,
            'ytd_payments' => $ytdPayments,
            'open_disputes_count' => $openDisputes,
            'active_payment_plans_count' => $activePaymentPlans,
        ];
    }
}
