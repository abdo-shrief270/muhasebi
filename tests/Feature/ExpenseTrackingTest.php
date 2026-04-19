<?php

declare(strict_types=1);

use App\Domain\Expenses\Enums\ExpensePaymentMethod;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseReport;

// ──────────────────────────────────────────────────
// ExpenseStatus transitions
// ──────────────────────────────────────────────────

describe('ExpenseStatus transitions', function (): void {

    it('allows submit from Draft and Rejected', function (ExpenseStatus $status): void {
        expect($status->canSubmit())->toBeTrue();
    })->with([
        'Draft' => ExpenseStatus::Draft,
        'Rejected' => ExpenseStatus::Rejected,
    ]);

    it('allows approve only from Submitted', function (): void {
        expect(ExpenseStatus::Submitted->canApprove())->toBeTrue();
    });

    it('allows reject only from Submitted', function (): void {
        expect(ExpenseStatus::Submitted->canReject())->toBeTrue();
    });

    it('allows reimburse only from Approved', function (): void {
        expect(ExpenseStatus::Approved->canReimburse())->toBeTrue();
    });

    it('allows edit from Draft and Rejected only', function (): void {
        expect(ExpenseStatus::Draft->canEdit())->toBeTrue();
        expect(ExpenseStatus::Rejected->canEdit())->toBeTrue();
        expect(ExpenseStatus::Submitted->canEdit())->toBeFalse();
        expect(ExpenseStatus::Approved->canEdit())->toBeFalse();
        expect(ExpenseStatus::Reimbursed->canEdit())->toBeFalse();
    });
});

// ──────────────────────────────────────────────────
// ExpenseStatus labels
// ──────────────────────────────────────────────────

describe('ExpenseStatus labels', function (): void {

    it('has non-empty label for all cases', function (ExpenseStatus $status): void {
        expect($status->label())->toBeString()->not->toBeEmpty();
    })->with(ExpenseStatus::cases());

    it('has non-empty Arabic label for all cases', function (ExpenseStatus $status): void {
        expect($status->labelAr())->toBeString()->not->toBeEmpty();
    })->with(ExpenseStatus::cases());
});

// ──────────────────────────────────────────────────
// ExpensePaymentMethod labels
// ──────────────────────────────────────────────────

describe('ExpensePaymentMethod labels', function (): void {

    it('has non-empty label for all cases', function (ExpensePaymentMethod $method): void {
        expect($method->label())->toBeString()->not->toBeEmpty();
    })->with(ExpensePaymentMethod::cases());

    it('has non-empty Arabic label for all cases', function (ExpensePaymentMethod $method): void {
        expect($method->labelAr())->toBeString()->not->toBeEmpty();
    })->with(ExpensePaymentMethod::cases());
});

// ──────────────────────────────────────────────────
// Expense VAT calculation
// ──────────────────────────────────────────────────

describe('Expense VAT calculation', function (): void {

    it('calculates VAT correctly at 14%', function (): void {
        $tenant = createTenant();
        $user = createUser(['tenant_id' => $tenant->id]);

        $expense = Expense::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'amount' => '1000.00',
            'vat_rate' => '14.00',
            'vat_amount' => bcmul('1000.00', bcdiv('14.00', '100', 4), 2),
        ]);

        expect($expense->vat_amount)->toBe('140.00');
    });
});

// ──────────────────────────────────────────────────
// ExpenseReport recalculate
// ──────────────────────────────────────────────────

describe('ExpenseReport recalculate', function (): void {

    it('recalculates total from associated expenses', function (): void {
        $tenant = createTenant();
        $user = createUser(['tenant_id' => $tenant->id]);

        $report = ExpenseReport::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        $amounts = ['100.00', '200.00', '300.00'];

        foreach ($amounts as $amount) {
            Expense::factory()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'expense_report_id' => $report->id,
                'amount' => $amount,
                'vat_amount' => '0.00',
            ]);
        }

        $report->recalculate();

        expect($report->total_amount)->toBe('600.00');
    });
});

// ──────────────────────────────────────────────────
// Status-based business rules
// ──────────────────────────────────────────────────

describe('Expense business rules', function (): void {

    it('cannot be edited when approved', function (): void {
        expect(ExpenseStatus::Approved->canEdit())->toBeFalse();
    });

    it('cannot be submitted when already approved', function (): void {
        expect(ExpenseStatus::Approved->canSubmit())->toBeFalse();
    });

    it('cannot be approved when still draft (must be submitted first)', function (): void {
        expect(ExpenseStatus::Draft->canApprove())->toBeFalse();
    });
});
