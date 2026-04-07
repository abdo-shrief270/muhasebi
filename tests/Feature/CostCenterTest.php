<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\CostCenterType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('CostCenterType enum', function (): void {

    it('has non-empty labels for all cases', function (): void {
        foreach (CostCenterType::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
            expect($case->labelAr())->toBeString()->not->toBeEmpty();
        }
    });

});

describe('Cost center hierarchy', function (): void {

    it('returns correct breadcrumb via fullPath', function (): void {
        $parent = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name_en' => 'HQ',
        ]);

        $child = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
            'parent_id' => $parent->id,
            'name_en' => 'Finance',
        ]);

        expect($child->fullPath())->toBe('HQ > Finance');
    });

});

describe('Circular reference prevention', function (): void {

    it('cannot set parent to self', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        expect($costCenter->wouldCreateCircle($costCenter->id))->toBeTrue();
    });

});

describe('P&L calculation', function (): void {

    it('calculates net profit correctly with bcmath', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $revenueAccount = Account::factory()->revenue()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $expenseAccount = Account::factory()->expense()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $journalEntry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Revenue line: 10000 credit
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $journalEntry->id,
            'account_id' => $revenueAccount->id,
            'cost_center_id' => $costCenter->id,
            'debit' => 0,
            'credit' => 10000,
        ]);

        // Expense line: 7000 debit
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $journalEntry->id,
            'account_id' => $expenseAccount->id,
            'cost_center_id' => $costCenter->id,
            'debit' => 7000,
            'credit' => 0,
        ]);

        $pnl = $costCenter->profitAndLoss();

        expect($pnl['revenue'])->toBe('10000.00');
        expect($pnl['expenses'])->toBe('7000.00');
        expect($pnl['net_profit'])->toBe('3000.00');
    });

});

describe('Cost analysis', function (): void {

    it('calculates budget variance and utilization', function (): void {
        $costCenter = CostCenter::factory()->withBudget(50000)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $expenseAccount = Account::factory()->expense()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $journalEntry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $journalEntry->id,
            'account_id' => $expenseAccount->id,
            'cost_center_id' => $costCenter->id,
            'debit' => 35000,
            'credit' => 0,
        ]);

        $analysis = $costCenter->costAnalysis();

        expect($analysis['budget'])->toBe('50000.00');
        expect($analysis['actual'])->toBe('35000.00');
        expect($analysis['variance'])->toBe('15000.00');
        expect($analysis['utilization'])->toBe('70.00');
    });

});

describe('Delete prevention', function (): void {

    it('cannot delete cost center with journal entries', function (): void {
        $costCenter = CostCenter::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $account = Account::factory()->expense()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $journalEntry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $journalEntry->id,
            'account_id' => $account->id,
            'cost_center_id' => $costCenter->id,
            'debit' => 1000,
            'credit' => 0,
        ]);

        expect($costCenter->hasJournalEntries())->toBeTrue();
    });

});
