<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Accounting\Models\StatementTemplate;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

// ──────────────────────────────────────
// Template CRUD
// ──────────────────────────────────────

describe('Statement Template CRUD', function (): void {

    it('creates a statement template with valid structure', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/statement-templates', [
                'name_ar' => 'قائمة الدخل',
                'name_en' => 'Income Statement',
                'type' => 'income_statement',
                'structure' => [
                    'sections' => [
                        [
                            'id' => 'revenue',
                            'label_ar' => 'الإيرادات',
                            'label_en' => 'Revenue',
                            'accounts' => ['type' => 'revenue'],
                            'subtotal' => true,
                        ],
                        [
                            'id' => 'expenses',
                            'label_ar' => 'المصروفات',
                            'label_en' => 'Expenses',
                            'accounts' => ['type' => 'expense'],
                            'subtotal' => true,
                            'negate' => true,
                        ],
                        [
                            'id' => 'net_income',
                            'label_ar' => 'صافي الدخل',
                            'label_en' => 'Net Income',
                            'formula' => 'revenue - expenses',
                            'is_calculated' => true,
                        ],
                    ],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name_ar', 'قائمة الدخل');
        $response->assertJsonPath('data.type', 'income_statement');
    });

    it('lists statement templates filtered by type', function (): void {
        StatementTemplate::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'قائمة الدخل',
            'type' => 'income_statement',
            'structure' => ['sections' => [['id' => 'rev', 'label_ar' => 'إيرادات', 'accounts' => ['type' => 'revenue']]]],
            'created_by' => $this->admin->id,
        ]);

        StatementTemplate::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'الميزانية العمومية',
            'type' => 'balance_sheet',
            'structure' => ['sections' => [['id' => 'assets', 'label_ar' => 'أصول', 'accounts' => ['type' => 'asset']]]],
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/statement-templates?type=income_statement');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.type'))->toBe('income_statement');
    });

    it('deletes a statement template', function (): void {
        $template = StatementTemplate::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'قالب للحذف',
            'type' => 'income_statement',
            'structure' => ['sections' => [['id' => 'rev', 'label_ar' => 'إيرادات', 'accounts' => ['type' => 'revenue']]]],
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/statement-templates/{$template->id}");

        $response->assertOk();
        expect(StatementTemplate::find($template->id))->toBeNull();
        expect(StatementTemplate::withTrashed()->find($template->id))->not->toBeNull();
    });
});

// ──────────────────────────────────────
// Template Structure & Section Subtotals
// ──────────────────────────────────────

describe('Statement Generation', function (): void {

    it('generates a statement with section subtotals from GL data', function (): void {
        // Create revenue account
        $revenueAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '4001',
            'name_ar' => 'إيرادات المبيعات',
            'type' => AccountType::Revenue,
            'normal_balance' => 'credit',
            'is_group' => false,
        ]);

        // Create expense account
        $expenseAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '6001',
            'name_ar' => 'مصروفات عمومية',
            'type' => AccountType::Expense,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        // Post journal entries: revenue 10000, expense 3000
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-15',
            'total_debit' => '10000.00',
            'total_credit' => '10000.00',
        ]);

        // Revenue credit
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => '10000.00',
        ]);

        // Cash debit (need a cash account)
        $cashAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1001',
            'name_ar' => 'نقدية',
            'type' => AccountType::Asset,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => '10000.00',
            'credit' => 0,
        ]);

        // Expense entry
        $entry2 = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-20',
            'total_debit' => '3000.00',
            'total_credit' => '3000.00',
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $expenseAccount->id,
            'debit' => '3000.00',
            'credit' => 0,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => '3000.00',
        ]);

        // Create template
        $template = StatementTemplate::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'قائمة الدخل',
            'type' => 'income_statement',
            'structure' => [
                'sections' => [
                    [
                        'id' => 'revenue',
                        'label_ar' => 'الإيرادات',
                        'label_en' => 'Revenue',
                        'accounts' => ['type' => 'revenue'],
                        'subtotal' => true,
                    ],
                    [
                        'id' => 'expenses',
                        'label_ar' => 'المصروفات',
                        'label_en' => 'Expenses',
                        'accounts' => ['type' => 'expense'],
                        'subtotal' => true,
                        'negate' => true,
                    ],
                    [
                        'id' => 'net_income',
                        'label_ar' => 'صافي الدخل',
                        'label_en' => 'Net Income',
                        'formula' => 'revenue - expenses',
                        'is_calculated' => true,
                    ],
                ],
            ],
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/statement-templates/{$template->id}/generate?from=2026-03-01&to=2026-03-31");

        $response->assertOk();

        $sections = $response->json('data.sections');
        expect($sections)->toHaveCount(3);

        // Revenue section subtotal = 10000
        expect($sections[0]['id'])->toBe('revenue');
        expect($sections[0]['subtotal'])->toBe('10000.00');

        // Expenses section subtotal = -3000 (negated)
        expect($sections[1]['id'])->toBe('expenses');
        expect($sections[1]['subtotal'])->toBe('-3000.00');

        // Net income = revenue - expenses = 10000 - (-3000) = 13000
        expect($sections[2]['id'])->toBe('net_income');
        expect($sections[2]['subtotal'])->toBe('13000.00');
        expect($sections[2]['is_calculated'])->toBeTrue();
    });

    it('generates statement with code range account selection', function (): void {
        // Create accounts in 5000-5999 range
        $cogsAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '5001',
            'name_ar' => 'تكلفة البضاعة المباعة',
            'type' => AccountType::Expense,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        $cashAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1001',
            'name_ar' => 'نقدية',
            'type' => AccountType::Asset,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-15',
            'total_debit' => '5000.00',
            'total_credit' => '5000.00',
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cogsAccount->id,
            'debit' => '5000.00',
            'credit' => 0,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => '5000.00',
        ]);

        $template = StatementTemplate::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'تكلفة مبيعات',
            'type' => 'income_statement',
            'structure' => [
                'sections' => [
                    [
                        'id' => 'cogs',
                        'label_ar' => 'تكلفة المبيعات',
                        'accounts' => ['codes_from' => '5000', 'codes_to' => '5999'],
                        'subtotal' => true,
                    ],
                ],
            ],
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/statement-templates/{$template->id}/generate?from=2026-03-01&to=2026-03-31");

        $response->assertOk();
        $sections = $response->json('data.sections');
        expect($sections[0]['subtotal'])->toBe('5000.00');
        expect($sections[0]['rows'])->toHaveCount(1);
        expect($sections[0]['rows'][0]['code'])->toBe('5001');
    });
});

// ──────────────────────────────────────
// Calculated Formula
// ──────────────────────────────────────

describe('Calculated Formula Sections', function (): void {

    it('evaluates formula from previous section totals', function (): void {
        // Revenue account
        $revenueAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '4001',
            'name_ar' => 'إيرادات',
            'type' => AccountType::Revenue,
            'normal_balance' => 'credit',
            'is_group' => false,
        ]);

        $cogsAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '5001',
            'name_ar' => 'تكلفة مبيعات',
            'type' => AccountType::Expense,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        $cashAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1001',
            'name_ar' => 'نقدية',
            'type' => AccountType::Asset,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        // Revenue 20000
        $entry1 = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-10',
            'total_debit' => '20000.00',
            'total_credit' => '20000.00',
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $cashAccount->id,
            'debit' => '20000.00',
            'credit' => 0,
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry1->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => '20000.00',
        ]);

        // COGS 8000
        $entry2 = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-12',
            'total_debit' => '8000.00',
            'total_credit' => '8000.00',
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $cogsAccount->id,
            'debit' => '8000.00',
            'credit' => 0,
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => '8000.00',
        ]);

        $template = StatementTemplate::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'مجمل ربح',
            'type' => 'income_statement',
            'structure' => [
                'sections' => [
                    [
                        'id' => 'revenue',
                        'label_ar' => 'الإيرادات',
                        'accounts' => ['type' => 'revenue'],
                        'subtotal' => true,
                    ],
                    [
                        'id' => 'cogs',
                        'label_ar' => 'تكلفة المبيعات',
                        'accounts' => ['codes_from' => '5000', 'codes_to' => '5999'],
                        'subtotal' => true,
                        'negate' => true,
                    ],
                    [
                        'id' => 'gross_profit',
                        'label_ar' => 'مجمل الربح',
                        'label_en' => 'Gross Profit',
                        'formula' => 'revenue - cogs',
                        'is_calculated' => true,
                    ],
                ],
            ],
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/statement-templates/{$template->id}/generate?from=2026-03-01&to=2026-03-31");

        $response->assertOk();
        $sections = $response->json('data.sections');

        // Revenue = 20000
        expect($sections[0]['subtotal'])->toBe('20000.00');
        // COGS = -8000 (negated)
        expect($sections[1]['subtotal'])->toBe('-8000.00');
        // Gross profit = 20000 - (-8000) = 28000
        expect($sections[2]['subtotal'])->toBe('28000.00');
    });
});

// ──────────────────────────────────────
// Financial Ratios
// ──────────────────────────────────────

describe('Financial Ratios', function (): void {

    it('calculates current ratio as assets divided by liabilities', function (): void {
        $assetAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1001',
            'name_ar' => 'نقدية',
            'type' => AccountType::Asset,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        $liabilityAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '2001',
            'name_ar' => 'دائنون',
            'type' => AccountType::Liability,
            'normal_balance' => 'credit',
            'is_group' => false,
        ]);

        // Assets = 10000 debit
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-15',
            'total_debit' => '10000.00',
            'total_credit' => '10000.00',
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $assetAccount->id,
            'debit' => '10000.00',
            'credit' => 0,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $liabilityAccount->id,
            'debit' => 0,
            'credit' => '10000.00',
        ]);

        // Additional liability = 5000
        $entry2 = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-20',
            'total_debit' => '5000.00',
            'total_credit' => '5000.00',
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $assetAccount->id,
            'debit' => '5000.00',
            'credit' => 0,
        ]);

        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $liabilityAccount->id,
            'debit' => 0,
            'credit' => '5000.00',
        ]);

        // Assets = 15000, Liabilities = 15000, current ratio = 1.0
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/statement-templates/ratios?from=2026-03-01&to=2026-03-31');

        $response->assertOk();
        $ratios = $response->json('data');

        $currentRatio = collect($ratios)->firstWhere('name', 'Current Ratio');
        expect($currentRatio)->not->toBeNull();
        expect($currentRatio['value'])->toBe('1.0000');
    });
});

// ──────────────────────────────────────
// Vertical Analysis
// ──────────────────────────────────────

describe('Vertical Analysis', function (): void {

    it('calculates each revenue/expense line as percentage of total revenue', function (): void {
        $revenueAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '4001',
            'name_ar' => 'إيرادات المبيعات',
            'type' => AccountType::Revenue,
            'normal_balance' => 'credit',
            'is_group' => false,
        ]);

        $expenseAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '6001',
            'name_ar' => 'مصروفات إدارية',
            'type' => AccountType::Expense,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        $cashAccount = Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1001',
            'name_ar' => 'نقدية',
            'type' => AccountType::Asset,
            'normal_balance' => 'debit',
            'is_group' => false,
        ]);

        // Revenue = 20000
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-10',
            'total_debit' => '20000.00',
            'total_credit' => '20000.00',
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'debit' => '20000.00',
            'credit' => 0,
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => '20000.00',
        ]);

        // Expenses = 5000
        $entry2 = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'date' => '2026-03-15',
            'total_debit' => '5000.00',
            'total_credit' => '5000.00',
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $expenseAccount->id,
            'debit' => '5000.00',
            'credit' => 0,
        ]);
        JournalEntryLine::factory()->create([
            'journal_entry_id' => $entry2->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => '5000.00',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/statement-templates/vertical-analysis?from=2026-03-01&to=2026-03-31');

        $response->assertOk();

        $accounts = $response->json('data.accounts');
        $revenueRow = collect($accounts)->firstWhere('code', '4001');
        $expenseRow = collect($accounts)->firstWhere('code', '6001');

        // Revenue = 20000, 20000/20000 * 100 = 100%
        expect($revenueRow['percentage'])->toBe('100.00');
        expect($revenueRow['base'])->toBe('revenue');

        // Expense = 5000, 5000/20000 * 100 = 25%
        expect($expenseRow['percentage'])->toBe('25.00');
        expect($expenseRow['base'])->toBe('revenue');
    });
});
