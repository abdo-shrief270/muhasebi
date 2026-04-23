<?php

declare(strict_types=1);

use App\Domain\AccountsPayable\Enums\BillStatus;
use App\Domain\AccountsPayable\Enums\PaymentMethod;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\AccountsPayable\Services\BillPaymentService;
use App\Domain\Workflow\Enums\ApprovalStatus;
use App\Domain\Workflow\Enums\ApproverType;
use App\Domain\Workflow\Models\ApprovalRequest;
use App\Domain\Workflow\Models\ApprovalWorkflow;
use App\Domain\Workflow\Services\ApprovalWorkflowService;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

// ──────────────────────────────────────
// Single-step workflow: submit → approve → status approved
// ──────────────────────────────────────

describe('Single-step workflow', function (): void {

    it('submits and approves through a single-step workflow', function (): void {
        // Create a single-step workflow
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approval-workflows', [
                'name_ar' => 'اعتماد المصروفات',
                'name_en' => 'Expense Approval',
                'entity_type' => 'expense',
                'steps' => [
                    [
                        'approver_type' => 'user',
                        'approver_id' => $this->admin->id,
                    ],
                ],
            ]);

        $response->assertCreated();
        $workflowId = $response->json('data.id');

        // Submit for approval
        $submitResponse = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'expense',
                'entity_id' => 1,
            ]);

        $submitResponse->assertCreated();
        $requestId = $submitResponse->json('data.id');

        expect($submitResponse->json('data.status'))->toBe('in_progress');
        expect($submitResponse->json('data.current_step'))->toBe(1);

        // Approve
        $approveResponse = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/approvals/{$requestId}/approve", [
                'comment' => 'Looks good',
            ]);

        $approveResponse->assertOk();
        expect($approveResponse->json('data.status'))->toBe('approved');
    });
});

// ──────────────────────────────────────
// Multi-step workflow: submit → step 1 approve → step 2 approve → approved
// ──────────────────────────────────────

describe('Multi-step workflow', function (): void {

    it('requires all steps to be approved before final approval', function (): void {
        $approver2 = User::factory()->admin()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create a two-step workflow
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approval-workflows', [
                'name_ar' => 'اعتماد متعدد المراحل',
                'entity_type' => 'bill',
                'steps' => [
                    [
                        'step_order' => 1,
                        'approver_type' => 'user',
                        'approver_id' => $this->admin->id,
                    ],
                    [
                        'step_order' => 2,
                        'approver_type' => 'user',
                        'approver_id' => $approver2->id,
                    ],
                ],
            ])
            ->assertCreated();

        // Submit
        $submitResponse = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'bill',
                'entity_id' => 42,
            ]);

        $submitResponse->assertCreated();
        $requestId = $submitResponse->json('data.id');

        // Approve step 1
        $step1Response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/approvals/{$requestId}/approve");

        $step1Response->assertOk();
        expect($step1Response->json('data.status'))->toBe('in_progress');
        expect($step1Response->json('data.current_step'))->toBe(2);

        // Approve step 2 (as the second approver)
        actingAsUser($approver2);
        $step2Response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/approvals/{$requestId}/approve");

        $step2Response->assertOk();
        expect($step2Response->json('data.status'))->toBe('approved');
    });
});

// ──────────────────────────────────────
// Rejection: submit → reject → status rejected
// ──────────────────────────────────────

describe('Rejection', function (): void {

    it('rejects a request with a comment', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approval-workflows', [
                'name_ar' => 'اعتماد قيود',
                'entity_type' => 'journal_entry',
                'steps' => [
                    ['approver_type' => 'user', 'approver_id' => $this->admin->id],
                ],
            ])
            ->assertCreated();

        $submitResponse = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'journal_entry',
                'entity_id' => 10,
            ]);

        $requestId = $submitResponse->json('data.id');

        // Reject
        $rejectResponse = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/approvals/{$requestId}/reject", [
                'comment' => 'المبلغ غير صحيح',
            ]);

        $rejectResponse->assertOk();
        expect($rejectResponse->json('data.status'))->toBe('rejected');
    });

    it('requires a comment when rejecting', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approval-workflows', [
                'name_ar' => 'اعتماد',
                'entity_type' => 'leave_request',
                'steps' => [
                    ['approver_type' => 'user', 'approver_id' => $this->admin->id],
                ],
            ])
            ->assertCreated();

        $submitResponse = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'leave_request',
                'entity_id' => 5,
            ]);

        $requestId = $submitResponse->json('data.id');

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/approvals/{$requestId}/reject", [])
            ->assertUnprocessable();
    });
});

// ──────────────────────────────────────
// Amount threshold: workflow only triggers when amount > limit
// ──────────────────────────────────────

describe('Amount threshold', function (): void {

    it('does not trigger workflow when amount is below approval limit', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approval-workflows', [
                'name_ar' => 'اعتماد المصروفات الكبيرة',
                'entity_type' => 'expense',
                'steps' => [
                    [
                        'approver_type' => 'user',
                        'approver_id' => $this->admin->id,
                        'approval_limit' => 5000,
                    ],
                ],
            ])
            ->assertCreated();

        // Submit with amount below limit — should not create a request
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'expense',
                'entity_id' => 99,
                'amount' => 3000,
            ]);

        $response->assertOk();
        expect($response->json('data'))->toBeNull();
    });

    it('triggers workflow when amount exceeds approval limit', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approval-workflows', [
                'name_ar' => 'اعتماد المصروفات الكبيرة',
                'entity_type' => 'payroll_run',
                'steps' => [
                    [
                        'approver_type' => 'user',
                        'approver_id' => $this->admin->id,
                        'approval_limit' => 5000,
                    ],
                ],
            ])
            ->assertCreated();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'payroll_run',
                'entity_id' => 99,
                'amount' => 10000,
            ]);

        $response->assertCreated();
        expect($response->json('data.status'))->toBe('in_progress');
    });
});

// ──────────────────────────────────────
// Pending list returns correct items for user
// ──────────────────────────────────────

describe('Pending list', function (): void {

    it('returns pending approvals assigned to the user', function (): void {
        // Create workflow assigned to admin
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approval-workflows', [
                'name_ar' => 'اعتماد فواتير',
                'entity_type' => 'bill',
                'steps' => [
                    ['approver_type' => 'user', 'approver_id' => $this->admin->id],
                ],
            ])
            ->assertCreated();

        // Submit two requests
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'bill',
                'entity_id' => 1,
            ])
            ->assertCreated();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'bill',
                'entity_id' => 2,
            ])
            ->assertCreated();

        // Check pending
        $pendingResponse = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/approvals/pending');

        $pendingResponse->assertOk();
        expect($pendingResponse->json('data'))->toHaveCount(2);
    });

    it('does not return pending approvals for a different user', function (): void {
        $otherUser = User::factory()->admin()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approval-workflows', [
                'name_ar' => 'اعتماد',
                'entity_type' => 'journal_entry',
                'steps' => [
                    ['approver_type' => 'user', 'approver_id' => $otherUser->id],
                ],
            ])
            ->assertCreated();

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/approvals/submit', [
                'entity_type' => 'journal_entry',
                'entity_id' => 1,
            ])
            ->assertCreated();

        // Admin checks pending — should not see request assigned to otherUser
        $pendingResponse = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/approvals/pending');

        $pendingResponse->assertOk();
        expect($pendingResponse->json('data'))->toHaveCount(0);
    });
});

describe('ApprovalWorkflowService::isApproved', function (): void {

    it('returns true when no active workflow exists for the entity type', function (): void {
        app()->instance('tenant.id', $this->tenant->id);
        $service = app(ApprovalWorkflowService::class);

        expect($service->isApproved('journal_entry', 999, 50000))->toBeTrue();
    });

    it('returns true when amount is below every step limit', function (): void {
        app()->instance('tenant.id', $this->tenant->id);
        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد',
            'entity_type' => 'journal_entry',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);

        $service = app(ApprovalWorkflowService::class);
        expect($service->isApproved('journal_entry', 1, 5000))->toBeTrue();
    });

    it('returns false when amount exceeds a step limit and no approved request exists', function (): void {
        app()->instance('tenant.id', $this->tenant->id);
        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد',
            'entity_type' => 'journal_entry',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);

        $service = app(ApprovalWorkflowService::class);
        expect($service->isApproved('journal_entry', 1, 50000))->toBeFalse();
    });

    it('returns false when a request exists but is still pending', function (): void {
        app()->instance('tenant.id', $this->tenant->id);
        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد',
            'entity_type' => 'journal_entry',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);
        ApprovalRequest::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $workflow->id,
            'entity_type' => 'journal_entry',
            'entity_id' => 1,
            'current_step' => 1,
            'status' => ApprovalStatus::InProgress,
            'requested_by' => $this->admin->id,
        ]);

        $service = app(ApprovalWorkflowService::class);
        expect($service->isApproved('journal_entry', 1, 50000))->toBeFalse();
    });

    it('returns true when a matching approved request exists', function (): void {
        app()->instance('tenant.id', $this->tenant->id);
        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد',
            'entity_type' => 'journal_entry',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);
        ApprovalRequest::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $workflow->id,
            'entity_type' => 'journal_entry',
            'entity_id' => 1,
            'current_step' => 1,
            'status' => ApprovalStatus::Approved,
            'requested_by' => $this->admin->id,
        ]);

        $service = app(ApprovalWorkflowService::class);
        expect($service->isApproved('journal_entry', 1, 50000))->toBeTrue();
    });
});

describe('BillPayment approval gate', function (): void {

    it('blocks BillPaymentService::record when amount exceeds workflow threshold', function (): void {
        app()->instance('tenant.id', $this->tenant->id);

        $vendor = Vendor::factory()->create(['tenant_id' => $this->tenant->id]);
        $bill = Bill::factory()->approved()->create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $vendor->id,
            'total' => '50000.00',
            'amount_paid' => '0.00',
        ]);

        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد دفع',
            'entity_type' => 'bill_payment',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);

        $service = app(BillPaymentService::class);

        $attempt = fn () => $service->record($bill, [
            'amount' => '30000',
            'date' => now()->toDateString(),
            'method' => PaymentMethod::BankTransfer,
        ]);

        expect($attempt)->toThrow(ValidationException::class);
        expect($bill->fresh()->amount_paid)->toBe('0.00');
        expect($bill->fresh()->status)->toBe(BillStatus::Approved);
    });
});
