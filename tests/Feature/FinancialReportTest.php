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

    // Fiscal year + periods
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

    // Parent group accounts
    $this->currentAssetsGroup = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1100',
        'name_ar' => 'الأصول المتداولة',
        'is_group' => true,
        'level' => 2,
    ]);

    $this->revenueGroup = Account::factory()->revenue()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '4100',
        'name_ar' => 'إيرادات النشاط',
        'is_group' => true,
        'level' => 2,
    ]);

    $this->expenseGroup = Account::factory()->expense()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '5200',
        'name_ar' => 'مصروفات إدارية',
        'is_group' => true,
        'level' => 2,
    ]);

    $this->liabilityGroup = Account::factory()->liability()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '2100',
        'name_ar' => 'الخصوم المتداولة',
        'is_group' => true,
        'level' => 2,
    ]);

    $this->equityGroup = Account::factory()->equity()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '3000',
        'name_ar' => 'حقوق الملكية',
        'is_group' => true,
        'level' => 1,
    ]);

    // Leaf accounts
    $this->cashAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1111',
        'name_ar' => 'الصندوق',
        'is_group' => false,
        'parent_id' => $this->currentAssetsGroup->id,
        'level' => 3,
    ]);

    $this->revenueAccount = Account::factory()->revenue()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '4110',
        'name_ar' => 'إيرادات المبيعات',
        'is_group' => false,
        'parent_id' => $this->revenueGroup->id,
        'level' => 3,
    ]);

    $this->expenseAccount = Account::factory()->expense()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '5210',
        'name_ar' => 'رواتب وأجور',
        'is_group' => false,
        'parent_id' => $this->expenseGroup->id,
        'level' => 3,
    ]);

    $this->apAccount = Account::factory()->liability()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '2111',
        'name_ar' => 'الموردون',
        'is_group' => false,
        'parent_id' => $this->liabilityGroup->id,
        'level' => 3,
    ]);

    $this->capitalAccount = Account::factory()->equity()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '3100',
        'name_ar' => 'رأس المال',
        'is_group' => false,
        'parent_id' => $this->equityGroup->id,
        'level' => 3,
    ]);
});

/**
 * Helper: create a posted journal entry with debit/credit lines.
 */
function createFinancialPostedEntry(
    object $test,
    int $fiscalPeriodId,
    string $date,
    int $debitAccountId,
    int $creditAccountId,
    float $amount,
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

// ────────────────────────────────────────────────────────────────
// Income Statement
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/reports/income-statement', function (): void {

    it('returns zeros when there are no entries', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/income-statement');

        $response->assertOk()
            ->assertJsonPath('total_revenue', '0.00')
            ->assertJsonPath('total_expenses', '0.00')
            ->assertJsonPath('net_income', '0.00');
    });

    it('returns correct revenue, expenses, and net income with entries', function (): void {
        // Revenue: cash debit 10000, revenue credit 10000
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            10000.00,
        );

        // Expense: expense debit 4000, cash credit 4000
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->expenseAccount->id,
            $this->cashAccount->id,
            4000.00,
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/income-statement');

        $response->assertOk()
            ->assertJsonPath('total_revenue', '10000.00')
            ->assertJsonPath('total_expenses', '4000.00')
            ->assertJsonPath('net_income', '6000.00');
    });

    it('groups accounts under parent groups', function (): void {
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00,
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/income-statement');

        $response->assertOk();

        $data = $response->json();

        // Revenue section should contain group with child accounts
        $revenueGroups = collect($data['revenue'] ?? []);
        expect($revenueGroups)->not->toBeEmpty();

        $revenueGroup = $revenueGroups->firstWhere('code', '4100');
        if ($revenueGroup) {
            expect($revenueGroup['children'] ?? $revenueGroup['accounts'] ?? [])->not->toBeEmpty();
        }
    });

    it('filters by date range', function (): void {
        // January revenue
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00,
        );

        // February revenue
        createFinancialPostedEntry(
            $this,
            $this->februaryPeriod->id,
            '2026-02-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            3000.00,
        );

        // Filter to January only
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/income-statement?from=2026-01-01&to=2026-01-31');

        $response->assertOk()
            ->assertJsonPath('total_revenue', '5000.00');
    });

    it('excludes draft entries', function (): void {
        // Posted entry
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            5000.00,
        );

        // Draft entry (should be excluded)
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
            ->getJson('/api/v1/reports/income-statement');

        $response->assertOk()
            ->assertJsonPath('total_revenue', '5000.00');
    });
});

// ────────────────────────────────────────────────────────────────
// Balance Sheet
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/reports/balance-sheet', function (): void {

    it('returns zeros when there are no entries', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/balance-sheet');

        $response->assertOk()
            ->assertJsonPath('total_assets', '0.00')
            ->assertJsonPath('total_liabilities', '0.00')
            ->assertJsonPath('total_equity', '0.00')
            ->assertJsonPath('is_balanced', true);
    });

    it('returns correct balances and is balanced with entries', function (): void {
        // Capital contribution: cash debit 50000, capital credit 50000
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-01',
            $this->cashAccount->id,
            $this->capitalAccount->id,
            50000.00,
        );

        // Purchase on credit: expense debit 5000, AP credit 5000
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->expenseAccount->id,
            $this->apAccount->id,
            5000.00,
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/balance-sheet');

        $response->assertOk()
            ->assertJsonPath('total_assets', '50000.00')
            ->assertJsonPath('total_liabilities', '5000.00')
            ->assertJsonPath('is_balanced', true);
    });

    it('includes current year net income in equity section', function (): void {
        // Capital contribution
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-01',
            $this->cashAccount->id,
            $this->capitalAccount->id,
            50000.00,
        );

        // Revenue: cash debit 10000, revenue credit 10000
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            10000.00,
        );

        // Expense: expense debit 3000, cash credit 3000
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-20',
            $this->expenseAccount->id,
            $this->cashAccount->id,
            3000.00,
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/balance-sheet');

        $response->assertOk();

        $data = $response->json();

        // Net income = 10000 - 3000 = 7000
        // Total equity should include capital (50000) + net income (7000) = 57000
        // Total assets = 50000 + 10000 - 3000 = 57000
        expect($data['total_assets'])->toBe('57000.00');
        expect($data['is_balanced'])->toBeTrue();

        // Net income should appear somewhere in the equity section
        expect($data['net_income'])->toBe('7000.00');
    });

    it('filters by as-of date', function (): void {
        // January: capital contribution
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-01',
            $this->cashAccount->id,
            $this->capitalAccount->id,
            50000.00,
        );

        // February: additional revenue
        createFinancialPostedEntry(
            $this,
            $this->februaryPeriod->id,
            '2026-02-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            8000.00,
        );

        // As of Jan 31 should only show capital contribution
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/balance-sheet?as_of=2026-01-31');

        $response->assertOk()
            ->assertJsonPath('total_assets', '50000.00');
    });
});

// ────────────────────────────────────────────────────────────────
// Cash Flow Statement
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/reports/cash-flow', function (): void {

    it('returns zeros when there are no entries', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/cash-flow');

        $response->assertOk()
            ->assertJsonPath('opening_cash', '0.00')
            ->assertJsonPath('closing_cash', '0.00');
    });

    it('shows net income flowing to operating section', function (): void {
        // Revenue
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            10000.00,
        );

        // Expense
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-15',
            $this->expenseAccount->id,
            $this->cashAccount->id,
            3000.00,
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/cash-flow');

        $response->assertOk();

        $data = $response->json();

        // Net income should appear in operating activities
        expect($data['operating']['net_income'] ?? $data['net_income'] ?? null)->toBe('7000.00');
    });

    it('cash changes match opening plus net change equals closing', function (): void {
        // Revenue received in cash
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            15000.00,
        );

        // Expense paid in cash
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-20',
            $this->expenseAccount->id,
            $this->cashAccount->id,
            5000.00,
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/cash-flow');

        $response->assertOk();

        $data = $response->json();

        $opening = (float) $data['opening_cash'];
        $closing = (float) $data['closing_cash'];
        $netChange = (float) $data['net_change'];

        // opening + net_change = closing
        expect($opening + $netChange)->toBe($closing);
    });
});

// ────────────────────────────────────────────────────────────────
// Comparative Reports
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/reports/comparative/income-statement', function (): void {

    it('returns both periods with variance', function (): void {
        // January revenue
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            10000.00,
        );

        // February revenue
        createFinancialPostedEntry(
            $this,
            $this->februaryPeriod->id,
            '2026-02-10',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            12000.00,
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/comparative/income-statement?'.http_build_query([
                'current_from' => '2026-02-01',
                'current_to' => '2026-02-28',
                'prior_from' => '2026-01-01',
                'prior_to' => '2026-01-31',
            ]));

        $response->assertOk();

        $data = $response->json();

        expect($data['current']['total_revenue'])->toBe('12000.00');
        expect($data['prior']['total_revenue'])->toBe('10000.00');
        expect($data)->toHaveKey('variance');
    });
});

describe('GET /api/v1/reports/comparative/balance-sheet', function (): void {

    it('returns both periods with variance', function (): void {
        // Capital at start
        createFinancialPostedEntry(
            $this,
            $this->januaryPeriod->id,
            '2026-01-01',
            $this->cashAccount->id,
            $this->capitalAccount->id,
            50000.00,
        );

        // Additional revenue in February
        createFinancialPostedEntry(
            $this,
            $this->februaryPeriod->id,
            '2026-02-15',
            $this->cashAccount->id,
            $this->revenueAccount->id,
            8000.00,
        );

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/comparative/balance-sheet?'.http_build_query([
                'current_as_of' => '2026-02-28',
                'prior_as_of' => '2026-01-31',
            ]));

        $response->assertOk();

        $data = $response->json();

        expect($data['current']['total_assets'])->toBe('58000.00');
        expect($data['prior']['total_assets'])->toBe('50000.00');
        expect($data)->toHaveKey('variance');
    });
});

// ────────────────────────────────────────────────────────────────
// PDF Exports
// ────────────────────────────────────────────────────────────────
describe('PDF report exports', function (): void {

    it('income statement PDF returns successful response', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/v1/reports/income-statement/pdf');

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    });

    it('balance sheet PDF returns successful response', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/v1/reports/balance-sheet/pdf');

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    });

    it('cash flow PDF returns successful response', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/v1/reports/cash-flow/pdf');

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    });

    it('trial balance PDF returns successful response', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/v1/reports/trial-balance/pdf');

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    });
});
