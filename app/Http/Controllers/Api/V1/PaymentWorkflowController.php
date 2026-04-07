<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\AutoApprovalRule;
use App\Domain\Billing\Models\PaymentSchedule;
use App\Domain\Billing\Services\PaymentWorkflowService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWorkflowController extends Controller
{
    public function __construct(
        private readonly PaymentWorkflowService $workflowService,
    ) {}

    /**
     * Schedule a payment for a bill.
     */
    public function schedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bill_id' => ['required', 'integer', 'exists:bills,id'],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
            'payment_method' => ['nullable', 'string', 'max:20'],
        ]);

        $schedule = $this->workflowService->schedulePayment(
            (int) $validated['bill_id'],
            $validated['scheduled_date'],
            $validated['payment_method'] ?? null,
        );

        return $this->created($schedule->load('bill.vendor'));
    }

    /**
     * Schedule multiple bills at once.
     */
    public function scheduleBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bill_ids' => ['required', 'array', 'min:1'],
            'bill_ids.*' => ['integer', 'exists:bills,id'],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $schedules = $this->workflowService->scheduleBulk(
            $validated['bill_ids'],
            $validated['scheduled_date'],
        );

        return $this->created($schedules->load('bill.vendor'));
    }

    /**
     * Approve a payment schedule.
     */
    public function approve(PaymentSchedule $paymentSchedule): JsonResponse
    {
        $schedule = $this->workflowService->approveSchedule($paymentSchedule);

        return $this->success($schedule->load('bill.vendor', 'approvedByUser'));
    }

    /**
     * List scheduled payments with filters.
     */
    public function listScheduled(Request $request): JsonResponse
    {
        $schedules = $this->workflowService->listScheduled([
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'vendor_id' => $request->query('vendor_id'),
            'bill_id' => $request->query('bill_id'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return $this->success($schedules);
    }

    /**
     * Get early discount opportunities.
     */
    public function discountOpportunities(): JsonResponse
    {
        $opportunities = $this->workflowService->earlyDiscountOpportunities();

        return $this->success($opportunities);
    }

    /**
     * List auto-approval rules.
     */
    public function autoRulesIndex(Request $request): JsonResponse
    {
        $rules = AutoApprovalRule::query()
            ->when($request->query('entity_type'), fn ($q, $type) => $q->forEntityType($type))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderBy('created_at', 'desc')
            ->paginate(min((int) ($request->query('per_page', 15)), 100));

        return $this->success($rules);
    }

    /**
     * Create an auto-approval rule.
     */
    public function autoRulesStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'in:invoice,bill,expense'],
            'condition_field' => ['required', 'string', 'in:amount,vendor_id,client_id,category'],
            'operator' => ['required', 'string', 'in:lt,lte,eq,gt,gte,in'],
            'condition_value' => ['required', 'string', 'max:255'],
            'auto_action' => ['required', 'string', 'in:approve,submit'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rule = $this->workflowService->createAutoRule($validated);

        return $this->created($rule);
    }

    /**
     * Update an auto-approval rule.
     */
    public function autoRulesUpdate(Request $request, AutoApprovalRule $autoApprovalRule): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['sometimes', 'string', 'in:invoice,bill,expense'],
            'condition_field' => ['sometimes', 'string', 'in:amount,vendor_id,client_id,category'],
            'operator' => ['sometimes', 'string', 'in:lt,lte,eq,gt,gte,in'],
            'condition_value' => ['sometimes', 'string', 'max:255'],
            'auto_action' => ['sometimes', 'string', 'in:approve,submit'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $autoApprovalRule->update($validated);

        return $this->success($autoApprovalRule->refresh());
    }

    /**
     * Delete an auto-approval rule.
     */
    public function autoRulesDestroy(AutoApprovalRule $autoApprovalRule): JsonResponse
    {
        $autoApprovalRule->delete();

        return $this->success(message: 'Auto-approval rule deleted successfully.');
    }
}
