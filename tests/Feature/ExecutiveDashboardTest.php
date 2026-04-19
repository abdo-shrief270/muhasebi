<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;

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

    $this->q1Period = FiscalPeriod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_year_id' => $this->fiscalYear->id,
        'name' => 'Q1 2026',
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-31',
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
        'code' => '5100',
        'name_ar' => 'تكلفة المبيعات',
        'is_group' => true,
        'level' => 2,
    ]);

    $this->opexGroup = Account::factory()->expense()->create([
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

    // Leaf accounts
    $this->cashAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1111',
        'name_ar' => 'الصندوق',
        'name_en' => 'Cash',
        'is_group' => false,
        'parent_id' => $this->currentAssetsGroup->id,
        'level' => 3,
    ]);

    $this->bankAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1112',
        'name_ar' => 'البنك',
        'name_en' => 'Bank',
        'is_group' => false,
        'parent_id' => $this->currentAssetsGroup->id,
        'level' => 3,
    ]);

    $this->arAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1121',
        'name_ar' => 'المدينون',
        'name_en' => 'Accounts Receivable',
        'is_group' => false,
        'parent_id' => $this->currentAssetsGroup->id,
        'level' => 3,
    ]);

    $this->inventoryAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1131',
        'name_ar' => 'المخزون',
        'name_en' => 'Inventory',
        'is_group' => false,
        'parent_id' => $this->currentAssetsGroup->id,
        'level' => 3,
    ]);

    $this->revenueAccount = Account::factory()->revenue()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '4110',
        'name_ar' => 'إيرادات المبيعات',
        'name_en' => 'Sales Revenue',
        'is_group' => false,
        'parent_id' => $this->revenueGroup->id,
        'level' => 3,
    ]);

    $this->cogsAccount = Account::factory()->expense()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '5110',
        'name_ar' => 'تكلفة المبيعات',
        'name_en' => 'Cost of Sales',
        'is_group' => false,
        'parent_id' => $this->expenseGroup->id,
        'level' => 3,
    ]);

    $this->opexAccount = Account::factory()->expense()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '5210',
        'name_ar' => 'مصروفات إدارية',
        'name_en' => 'Admin Expense',
        'is_group' => false,
        'parent_id' => $this->opexGroup->id,
        'level' => 3,
    ]);

    $this->apAccount = Account::factory()->liability()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '2111',
        'name_ar' => 'الموردون',
        'name_en' => 'Accounts Payable',
        'is_group' => false,
        'parent_id' => $this->liabilityGroup->id,
        'level' => 3,
    ]);
});

/**
 * Helper: create a posted journal entry with debit/credit lines.
 */
function createDashboardEntry(
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
// KPI Dashboard
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/dashboard/kpis', function (): void {

    it('calculates DSO correctly: AR 100000, credit sales 300000, period 90 days = DSO 30', function (): void {
        // AR balance: debit AR 100000 (cash credit 100000)
        createDashboardEntry($this, $this->q1Period->id, '2026-01-15', $this->arAccount->id, $this->revenueAccount->id, 100000.00);

        // Additional revenue to make total credit sales = 300000
        createDashboardEntry($this, $this->q1Period->id, '2026-02-10', $this->cashAccount->id, $this->revenueAccount->id, 200000.00);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/kpis?from=2026-01-01&to=2026-03-31');

        $response->assertOk();

        // DSO = (AR / Credit Sales) * Period Days = (100000 / 300000) * 90 = 30
        expect($response->json('data.dso'))->toBe('30.00');
    });

    it('calculates current ratio correctly: assets 200000, liabilities 100000 = 2.0', function (): void {
        // Current assets: cash 200000
        createDashboardEntry($this, $this->q1Period->id, '2026-01-05', $this->cashAccount->id, $this->revenueAccount->id, 200000.00);

        // Current liabilities: AP 100000
        createDashboardEntry($this, $this->q1Period->id, '2026-01-10', $this->opexAccount->id, $this->apAccount->id, 100000.00);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/kpis?from=2026-01-01&to=2026-03-31');

        $response->assertOk();

        // Current ratio = 200000 / 100000 = 2.00
        expect($response->json('data.current_ratio'))->toBe('2.00');
    });

    it('returns KPI structure with all fields', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/kpis?from=2026-01-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['dso', 'dpo', 'current_ratio', 'quick_ratio', 'collection_rate'],
            ]);
    });
});

// ────────────────────────────────────────────────────────────────
// Cash Flow Forecast
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/dashboard/cash-flow', function (): void {

    it('projects 30-day cash: current 50000, AR weighted 19000, AP due 15000 = 54000', function (): void {
        // Cash balance of 50000
        createDashboardEntry($this, $this->q1Period->id, '2026-01-05', $this->cashAccount->id, $this->revenueAccount->id, 50000.00);

        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        // AR 20000 in the "current" bucket weighted at 95% -> 19000 exact.
        // Using a clean value avoids bcmath scale-2 truncation that would
        // leave the projection a cent short (e.g. 21052.63 × 0.95 = 19999.99).
        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'date' => '2026-01-20',
            'due_date' => '2026-04-15', // future due date -> current bucket
            'total' => 20000.00,
            'amount_paid' => 0,
        ]);

        // AP due within 30 days: 15000
        // vendor_id FKs to vendors.id (not clients.id) — use a real Vendor.
        $vendor = Vendor::factory()->create(['tenant_id' => $this->tenant->id]);
        Bill::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $vendor->id,
            'bill_number' => 'BILL-001',
            'date' => '2026-01-10',
            'due_date' => '2026-04-10', // within 30 days from March 31
            'status' => 'approved',
            'subtotal' => 15000,
            'vat_amount' => 0,
            'wht_amount' => 0,
            'total' => 15000,
            'amount_paid' => 0,
            'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/cash-flow?to=2026-03-31');

        $response->assertOk();
        expect($response->json('data.current_cash_position'))->toBe('50000.00');
        // projected_30_days = 50000 + (19000 - 15000) = 54000
        expect($response->json('data.projected_30_days'))->toBe('54000.00');
    });

    it('returns cash flow structure', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/cash-flow?to=2026-03-31');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'current_cash_position',
                    'expected_inflows',
                    'expected_outflows',
                    'projected_30_days',
                    'projected_60_days',
                    'projected_90_days',
                ],
            ]);
    });
});

// ────────────────────────────────────────────────────────────────
// Profitability
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/dashboard/profitability', function (): void {

    it('calculates gross margin: revenue 100000, COGS 60000 = 40%', function (): void {
        // Revenue 100000
        createDashboardEntry($this, $this->q1Period->id, '2026-01-10', $this->cashAccount->id, $this->revenueAccount->id, 100000.00);

        // COGS 60000 (expense account with code 5110 starting with '51')
        createDashboardEntry($this, $this->q1Period->id, '2026-01-15', $this->cogsAccount->id, $this->cashAccount->id, 60000.00);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/profitability?from=2026-01-01&to=2026-03-31');

        $response->assertOk();

        // Gross margin = (100000 - 60000) / 100000 * 100 = 40.00
        expect($response->json('data.gross_margin_percent'))->toBe('40.00');
    });

    it('returns profitability structure', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/profitability?from=2026-01-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'gross_margin_percent',
                    'net_margin_percent',
                    'operating_expense_ratio',
                    'revenue_per_client',
                    'top_profitable_clients',
                ],
            ]);
    });
});

// ────────────────────────────────────────────────────────────────
// Period Comparison
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/dashboard/comparison', function (): void {

    it('calculates variance: period A revenue 100000, period B 120000 = +20000 (+20%)', function (): void {
        $q2Period = FiscalPeriod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'name' => 'Q2 2026',
            'period_number' => 2,
            'start_date' => '2026-04-01',
            'end_date' => '2026-06-30',
        ]);

        // Period A revenue: 100000
        createDashboardEntry($this, $this->q1Period->id, '2026-01-10', $this->cashAccount->id, $this->revenueAccount->id, 100000.00);

        // Period B revenue: 120000
        createDashboardEntry($this, $q2Period->id, '2026-04-10', $this->cashAccount->id, $this->revenueAccount->id, 120000.00);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/comparison?period_a=2026-01-01:2026-03-31&period_b=2026-04-01:2026-06-30');

        $response->assertOk();

        $data = $response->json('data');
        expect($data['period_a']['revenue'])->toBe('100000.00');
        expect($data['period_b']['revenue'])->toBe('120000.00');
        expect($data['variance']['revenue']['change'])->toBe('20000.00');
        expect($data['variance']['revenue']['change_percent'])->toBe('20.00');
    });
});

// ────────────────────────────────────────────────────────────────
// Overview
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/dashboard/overview', function (): void {

    it('returns financial overview structure', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/overview?from=2026-01-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'revenue_ytd',
                    'expenses_ytd',
                    'net_profit_ytd',
                    'cash_balance',
                    'ar_outstanding',
                    'ap_outstanding',
                    'revenue_vs_last_year' => ['current', 'previous', 'change', 'change_percent'],
                    'expense_vs_budget' => ['actual', 'budget', 'variance', 'variance_percent'],
                ],
            ]);
    });

    it('calculates correct revenue and expense totals', function (): void {
        createDashboardEntry($this, $this->q1Period->id, '2026-01-10', $this->cashAccount->id, $this->revenueAccount->id, 50000.00);
        createDashboardEntry($this, $this->q1Period->id, '2026-02-10', $this->opexAccount->id, $this->cashAccount->id, 20000.00);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/overview?from=2026-01-01&to=2026-03-31');

        $response->assertOk();
        expect($response->json('data.revenue_ytd'))->toBe('50000.00');
        expect($response->json('data.expenses_ytd'))->toBe('20000.00');
        expect($response->json('data.net_profit_ytd'))->toBe('30000.00');
    });
});

// ────────────────────────────────────────────────────────────────
// Revenue Analysis
// ────────────────────────────────────────────────────────────────
describe('GET /api/v1/dashboard/revenue', function (): void {

    it('returns revenue analysis structure', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard/revenue?from=2026-01-01&to=2026-03-31');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'by_month',
                    'by_client',
                    'by_account',
                    'growth_rates',
                ],
            ]);
    });
});
