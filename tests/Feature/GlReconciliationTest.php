<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Accounting\Services\GlReconciliationService;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    $fy = FiscalYear::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);
    $this->fiscalPeriod = FiscalPeriod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_year_id' => $fy->id,
        'name' => 'FY 2026',
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    $this->cash = Account::factory()->asset()->create(['tenant_id' => $this->tenant->id, 'code' => '1111']);
    $this->revenue = Account::factory()->revenue()->create(['tenant_id' => $this->tenant->id, 'code' => '4110']);
});

describe('GlReconciliationService', function (): void {

    it('reports ok when every posted entry balances and sums match', function (): void {
        // Two balanced posted entries.
        foreach ([1000, 500] as $amount) {
            $entry = JournalEntry::factory()->posted()->create([
                'tenant_id' => $this->tenant->id,
                'fiscal_period_id' => $this->fiscalPeriod->id,
                'total_debit' => $amount,
                'total_credit' => $amount,
            ]);
            JournalEntryLine::factory()->debit($amount)->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $this->cash->id,
            ]);
            JournalEntryLine::factory()->credit($amount)->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $this->revenue->id,
            ]);
        }

        $report = app(GlReconciliationService::class)->reconcileTenant($this->tenant->id);

        expect($report['ok'])->toBeTrue();
        expect($report['entries_checked'])->toBe(2);
        expect($report['variance'])->toBe('0.00');
        expect($report['unbalanced_entries'])->toBe([]);
        expect($report['line_sum_mismatches'])->toBe([]);
    });

    it('flags an entry whose header totals do not match', function (): void {
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'total_debit' => 1000,
            'total_credit' => 900,
        ]);
        JournalEntryLine::factory()->debit(1000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cash->id,
        ]);
        JournalEntryLine::factory()->credit(900)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenue->id,
        ]);

        $report = app(GlReconciliationService::class)->reconcileTenant($this->tenant->id);

        expect($report['ok'])->toBeFalse();
        expect($report['variance'])->toBe('100.00');
        expect($report['unbalanced_entries'])->toHaveCount(1);
        expect($report['unbalanced_entries'][0]['id'])->toBe($entry->id);
    });

    it('flags a line-sum mismatch against the header', function (): void {
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'total_debit' => 1000,
            'total_credit' => 1000,
        ]);
        // Lines say 800/1000 — header claims 1000/1000.
        JournalEntryLine::factory()->debit(800)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cash->id,
        ]);
        JournalEntryLine::factory()->credit(1000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenue->id,
        ]);

        $report = app(GlReconciliationService::class)->reconcileTenant($this->tenant->id);

        expect($report['ok'])->toBeFalse();
        expect($report['line_sum_mismatches'])->toHaveCount(1);
        expect($report['line_sum_mismatches'][0]['entry_number'])->toBe($entry->entry_number);
        expect($report['line_sum_mismatches'][0]['header_debit'])->toBe('1000.00');
        expect($report['line_sum_mismatches'][0]['line_debit'])->toBe('800.00');
    });

    it('ignores draft and reversed entries', function (): void {
        // Draft with imbalanced totals — should not count.
        JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 1000,
            'total_credit' => 500,
        ]);

        $report = app(GlReconciliationService::class)->reconcileTenant($this->tenant->id);

        expect($report['ok'])->toBeTrue();
        expect($report['entries_checked'])->toBe(0);
    });
});

describe('gl:reconcile command', function (): void {

    it('exits 0 when all tenants are balanced', function (): void {
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'total_debit' => 100,
            'total_credit' => 100,
        ]);
        JournalEntryLine::factory()->debit(100)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cash->id,
        ]);
        JournalEntryLine::factory()->credit(100)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenue->id,
        ]);

        $this->artisan('gl:reconcile', ['--tenant' => $this->tenant->id])->assertExitCode(0);
    });

    it('exits non-zero when any tenant has a variance', function (): void {
        JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'total_debit' => 200,
            'total_credit' => 100,
        ]);

        $this->artisan('gl:reconcile', ['--tenant' => $this->tenant->id])->assertFailed();
    });
});
