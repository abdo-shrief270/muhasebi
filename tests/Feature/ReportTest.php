<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    // Fiscal year + period
    $this->fiscalYear = FiscalYear::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    $this->januaryPeriod = FiscalPeriod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_year_id' => $this->fiscalYear->id,
        'name' => 'January 2026',
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $this->februaryPeriod = FiscalPeriod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_year_id' => $this->fiscalYear->id,
        'name' => 'February 2026',
        'period_number' => 2,
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
    ]);

    // Leaf accounts
    $this->cashAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1111',
        'name_ar' => 'الصندوق',
        'is_group' => false,
    ]);

    $this->revenueAccount = Account::factory()->revenue()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '4110',
        'name_ar' => 'إيرادات المبيعات',
        'is_group' => false,
    ]);

    $this->expenseAccount = Account::factory()->expense()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '5210',
        'name_ar' => 'رواتب وأجور',
        'is_group' => false,
    ]);
});

/**
 * Helper: create a posted journal entry with debit/credit lines.
 */
function createPostedEntry(
    object $test,
    int $fiscalPeriodId,
    string $date,
    int $debitAccountId,
    int $creditAccountId,
    float $amount
): JournalEntry {
    $entry = JournalEntry::factory()->posted()->create([
        'tenant_id' => $test->tenant->id,
        'fiscal_period_id' => $fiscalPeriodId,
        'date' => $date,
        'total_debit' => $amount,
        'total_credit' => $amount,
        'posted_by' => $test->admin->id,
        'created_by' => $test->admin->id,
    ]);

    JournalEntryLine::factory()->debit($amount)->create([
        'journal_entry_id' => $entry->id,
        'account_id' => $debitAccountId,
    ]);

    JournalEntryLine::factory()->credit($amount)->create([
        'journal_entry_id' => $entry->id,
        'account_id' => $creditAccountId,
    ]);

    return $entry;
}

describe('GET /api/v1/reports/trial-balance', function (): void {

    it('returns all zeros when there are no entries', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/trial-balance');

        $response->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('totals.period_debit', '0.00')
            ->assertJsonPath('totals.period_credit', '0.00')
            ->assertJsonPath('totals.closing_debit', '0.00')
            ->assertJsonPath('totals.closing_credit', '0.00');
    });

    it('returns correct balances with posted entries', function (): void {
        // Cash 5000 debit, Revenue 5000 credit
        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/trial-balance');

        $response->assertOk();

        $rows = $response->json('rows');
        expect(count($rows))->toBe(2);

        // Find cash account row
        $cashRow = collect($rows)->firstWhere('account_code', '1111');
        expect($cashRow)->not->toBeNull();
        expect($cashRow['closing_debit'])->toBe('5000.00');

        // Find revenue account row
        $revenueRow = collect($rows)->firstWhere('account_code', '4110');
        expect($revenueRow)->not->toBeNull();
        expect($revenueRow['closing_credit'])->toBe('5000.00');
    });

    it('trial balance is balanced (total debits equal total credits)', function (): void {
        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            3000.00
        );

        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-20',
            $this->expenseAccount->id,
            $this->cashAccount->id,
            1000.00
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/trial-balance');

        $response->assertOk();

        $totals = $response->json('totals');
        expect($totals['closing_debit'])->toBe($totals['closing_credit']);
        expect($totals['period_debit'])->toBe($totals['period_credit']);
    });

    it('filters by date range', function (): void {
        // January entry
        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00
        );

        // February entry
        createPostedEntry(
            $this,
            $this->februaryPeriod->id,
            '2026-02-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            3000.00
        );

        // Filter to February only — January entry becomes opening balance
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/trial-balance?from=2026-02-01&to=2026-02-28');

        $response->assertOk();

        $rows = $response->json('rows');
        $cashRow = collect($rows)->firstWhere('account_code', '1111');

        // Period movement should be 3000 (February only)
        expect($cashRow['period_debit'])->toBe('3000.00');
        // Opening should be 5000 (January)
        expect($cashRow['opening_debit'])->toBe('5000.00');
    });

    it('excludes draft entries from trial balance', function (): void {
        // Posted entry
        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00
        );

        // Draft entry (should not appear)
        $draftEntry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->januaryPeriod->id,
            'date' => '2026-01-20',
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 9999,
            'total_credit' => 9999,
        ]);

        JournalEntryLine::factory()->debit(9999)->create([
            'journal_entry_id' => $draftEntry->id,
            'account_id' => $this->cashAccount->id,
        ]);

        JournalEntryLine::factory()->credit(9999)->create([
            'journal_entry_id' => $draftEntry->id,
            'account_id' => $this->revenueAccount->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/trial-balance');

        $response->assertOk();

        $cashRow = collect($response->json('rows'))->firstWhere('account_code', '1111');
        // Should only reflect the posted 5000, not the draft 9999
        expect($cashRow['closing_debit'])->toBe('5000.00');
    });
});

describe('GET /api/v1/reports/accounts/{account}/ledger', function (): void {

    it('returns account ledger with running balance', function (): void {
        // Entry 1: Cash debit 5000
        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00
        );

        // Entry 2: Cash credit 2000 (expense paid from cash)
        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-20',
            $this->expenseAccount->id,
            $this->cashAccount->id,
            2000.00
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/reports/accounts/{$this->cashAccount->id}/ledger");

        $response->assertOk();

        $transactions = $response->json('transactions');
        expect(count($transactions))->toBe(2);

        // First transaction: cash debited 5000, running balance = 5000
        expect($transactions[0]['debit'])->toBe('5000.00');
        expect($transactions[0]['running_balance'])->toBe('5000.00');

        // Second transaction: cash credited 2000, running balance = 3000
        expect($transactions[1]['credit'])->toBe('2000.00');
        expect($transactions[1]['running_balance'])->toBe('3000.00');

        // Closing balance should be 3000
        expect($response->json('closing_balance'))->toBe('3000.00');
    });

    it('filters by date range', function (): void {
        // January entry
        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00
        );

        // February entry
        createPostedEntry(
            $this,
            $this->februaryPeriod->id,
            '2026-02-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            3000.00
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/reports/accounts/{$this->cashAccount->id}/ledger?from=2026-02-01&to=2026-02-28");

        $response->assertOk();

        // Opening balance should include January (5000)
        expect($response->json('opening_balance'))->toBe('5000.00');

        // Only February transaction in the list
        $transactions = $response->json('transactions');
        expect(count($transactions))->toBe(1);
        expect($transactions[0]['debit'])->toBe('3000.00');

        // Closing balance = 5000 + 3000 = 8000
        expect($response->json('closing_balance'))->toBe('8000.00');
    });

    it('excludes draft entries from ledger', function (): void {
        // Posted entry
        createPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00
        );

        // Draft entry (should not appear)
        $draftEntry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->januaryPeriod->id,
            'date' => '2026-01-20',
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 9999,
            'total_credit' => 9999,
        ]);

        JournalEntryLine::factory()->debit(9999)->create([
            'journal_entry_id' => $draftEntry->id,
            'account_id' => $this->cashAccount->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/reports/accounts/{$this->cashAccount->id}/ledger");

        $response->assertOk();

        // Should only see the posted entry
        expect(count($response->json('transactions')))->toBe(1);
        expect($response->json('closing_balance'))->toBe('5000.00');
    });
});
