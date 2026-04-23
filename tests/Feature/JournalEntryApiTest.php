<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Workflow\Enums\ApprovalStatus;
use App\Domain\Workflow\Enums\ApproverType;
use App\Domain\Workflow\Models\ApprovalRequest;
use App\Domain\Workflow\Models\ApprovalWorkflow;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    // Create fiscal year + period covering current date
    $this->fiscalYear = FiscalYear::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    // Cover the whole fiscal year in a single period for tests — reversals
    // post on today's date and need a fiscal period to land in.
    $this->fiscalPeriod = FiscalPeriod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_year_id' => $this->fiscalYear->id,
        'name' => 'FY 2026',
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    // Create leaf test accounts
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

describe('GET /api/v1/journal-entries', function (): void {

    it('lists journal entries with pagination', function (): void {
        JournalEntry::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/journal-entries');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'entry_number', 'date', 'description', 'status', 'total_debit', 'total_credit']],
                'links',
                'meta',
            ]);
    });

    it('filters by status', function (): void {
        JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
        ]);
        JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'posted_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/journal-entries?status=posted');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('does not show entries from other tenants', function (): void {
        $otherTenant = createTenant();
        JournalEntry::factory()->create(['tenant_id' => $otherTenant->id]);
        JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/journal-entries');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('POST /api/v1/journal-entries', function (): void {

    it('creates a journal entry with balanced lines', function (): void {
        $data = [
            'date' => '2026-01-15',
            'description' => 'تحصيل إيرادات مبيعات',
            'reference' => 'INV-001',
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => 5000.00,
                    'credit' => 0,
                    'description' => 'تحصيل نقدي',
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => 0,
                    'credit' => 5000.00,
                    'description' => 'إيرادات مبيعات',
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        $response->assertCreated()
            ->assertJsonPath('data.description', 'تحصيل إيرادات مبيعات')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_debit', '5000.00')
            ->assertJsonPath('data.total_credit', '5000.00');

        // Verify entry_number was auto-generated
        expect($response->json('data.entry_number'))->toStartWith('JE-');

        // Verify lines were created
        $this->assertDatabaseCount('journal_entry_lines', 2);
    });

    it('rejects unbalanced journal entry', function (): void {
        $data = [
            'date' => '2026-01-15',
            'description' => 'قيد غير متوازن',
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => 5000.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => 0,
                    'credit' => 3000.00,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);
    });

    it('rejects entry with less than 2 lines', function (): void {
        $data = [
            'date' => '2026-01-15',
            'description' => 'قيد بسطر واحد',
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => 1000.00,
                    'credit' => 0,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);
    });

    it('rejects entry with line having both debit and credit greater than zero', function (): void {
        $data = [
            'date' => '2026-01-15',
            'description' => 'قيد خاطئ',
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => 1000.00,
                    'credit' => 500.00,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => 0,
                    'credit' => 500.00,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        $response->assertUnprocessable();
    });

    it('rejects entry posting to group account', function (): void {
        $groupAccount = Account::factory()->asset()->group()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1100',
        ]);

        $data = [
            'date' => '2026-01-15',
            'description' => 'قيد على حساب مجموعة',
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'lines' => [
                [
                    'account_id' => $groupAccount->id,
                    'debit' => 1000.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => 0,
                    'credit' => 1000.00,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        $response->assertUnprocessable();
    });

    it('rejects entry posting to inactive account', function (): void {
        $inactiveAccount = Account::factory()->asset()->inactive()->create([
            'tenant_id' => $this->tenant->id,
            'code' => '1199',
        ]);

        $data = [
            'date' => '2026-01-15',
            'description' => 'قيد على حساب غير نشط',
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'lines' => [
                [
                    'account_id' => $inactiveAccount->id,
                    'debit' => 1000.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => 0,
                    'credit' => 1000.00,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        $response->assertUnprocessable();
    });

    it('auto-generates entry number', function (): void {
        $data = [
            'date' => '2026-01-15',
            'description' => 'قيد أول',
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => 1000.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => 0,
                    'credit' => 1000.00,
                ],
            ],
        ];

        $response1 = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        $data['description'] = 'قيد ثاني';

        $response2 = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        $response1->assertCreated();
        $response2->assertCreated();

        $number1 = $response1->json('data.entry_number');
        $number2 = $response2->json('data.entry_number');

        expect($number1)->toStartWith('JE-');
        expect($number2)->toStartWith('JE-');
        expect($number1)->not->toBe($number2);
    });
});

describe('GET /api/v1/journal-entries/{journalEntry}', function (): void {

    it('shows journal entry with lines', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'total_debit' => 1000,
            'total_credit' => 1000,
            'created_by' => $this->admin->id,
        ]);

        JournalEntryLine::factory()->debit(1000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
        ]);

        JournalEntryLine::factory()->credit(1000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/journal-entries/{$entry->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonCount(2, 'data.lines');
    });
});

describe('PUT /api/v1/journal-entries/{journalEntry}', function (): void {

    it('updates a draft entry', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'created_by' => $this->admin->id,
        ]);

        $data = [
            'date' => '2026-01-20',
            'description' => 'قيد محدث',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => 2000.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => 0,
                    'credit' => 2000.00,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/journal-entries/{$entry->id}", $data);

        $response->assertOk()
            ->assertJsonPath('data.description', 'قيد محدث')
            ->assertJsonPath('data.total_debit', '2000.00')
            ->assertJsonPath('data.total_credit', '2000.00');
    });

    it('cannot update a posted entry', function (): void {
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'posted_by' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $data = [
            'date' => '2026-01-20',
            'description' => 'محاولة تعديل',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'debit' => 1000.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'debit' => 0,
                    'credit' => 1000.00,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/journal-entries/{$entry->id}", $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });
});

describe('DELETE /api/v1/journal-entries/{journalEntry}', function (): void {

    it('deletes a draft entry', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/journal-entries/{$entry->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Journal entry deleted successfully.');

        $this->assertSoftDeleted('journal_entries', ['id' => $entry->id]);
    });

    it('cannot delete a posted entry', function (): void {
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'posted_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/journal-entries/{$entry->id}");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });
});

describe('POST /api/v1/journal-entries/{journalEntry}/post', function (): void {

    it('posts a draft entry', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 3000,
            'total_credit' => 3000,
            'created_by' => $this->admin->id,
        ]);

        JournalEntryLine::factory()->debit(3000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
        ]);

        JournalEntryLine::factory()->credit(3000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        $response->assertOk()
            ->assertJsonPath('data.status', 'posted');

        expect($response->json('data.posted_at'))->not->toBeNull();
    });

    it('cannot post an already posted entry', function (): void {
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'posted_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });
});

describe('POST /api/v1/journal-entries/{journalEntry}/reverse', function (): void {

    it('reverses a posted entry and creates reversal entry', function (): void {
        $entry = JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'total_debit' => 2000,
            'total_credit' => 2000,
            'posted_by' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        JournalEntryLine::factory()->debit(2000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
        ]);

        JournalEntryLine::factory()->credit(2000)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/reverse");

        $response->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        // Reversal entry should have swapped amounts
        expect($response->json('data.total_debit'))->toBe('2000.00');
        expect($response->json('data.total_credit'))->toBe('2000.00');

        // Original entry should now be reversed
        $entry->refresh();
        expect($entry->status)->toBe(JournalEntryStatus::Reversed);
        expect($entry->reversed_at)->not->toBeNull();
    });

    it('cannot reverse a draft entry', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/reverse");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });

    it('cannot reverse an already reversed entry', function (): void {
        $entry = JournalEntry::factory()->reversed()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'posted_by' => $this->admin->id,
            'reversed_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/reverse");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });
});

describe('Fiscal period lock enforcement', function (): void {

    it('rejects creating an entry in a closed period', function (): void {
        $this->fiscalPeriod->update(['is_closed' => true, 'closed_at' => now()]);

        $data = [
            'date' => '2026-01-15',
            'description' => 'Test',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 100],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        expect($response->status())->toBe(423);
        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('rejects creating an entry in a locked period', function (): void {
        $this->fiscalPeriod->update(['is_locked' => true, 'locked_at' => now()]);

        $data = [
            'date' => '2026-01-15',
            'description' => 'Test',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 100],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/journal-entries', $data);

        expect($response->status())->toBe(423);
        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('rejects posting a draft entry into a locked period', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 100,
            'total_credit' => 100,
        ]);

        JournalEntryLine::factory()->debit(100)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
        ]);
        JournalEntryLine::factory()->credit(100)->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
        ]);

        $this->fiscalPeriod->update(['is_locked' => true, 'locked_at' => now()]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        expect($response->status())->toBe(423);
        expect($entry->fresh()->status)->toBe(JournalEntryStatus::Draft);
    });

    it('rejects deleting a draft entry in a locked period', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
        ]);

        $this->fiscalPeriod->update(['is_locked' => true, 'locked_at' => now()]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/journal-entries/{$entry->id}");

        expect($response->status())->toBe(423);
        expect(JournalEntry::query()->where('id', $entry->id)->exists())->toBeTrue();
    });
});

describe('Approval workflow enforcement on JE post', function (): void {

    it('allows posting when no workflow is configured', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 50000,
            'total_credit' => 50000,
        ]);
        JournalEntryLine::factory()->debit(50000)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->cashAccount->id]);
        JournalEntryLine::factory()->credit(50000)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->revenueAccount->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        $response->assertOk()
            ->assertJsonPath('data.status', 'posted');
    });

    it('allows posting when amount is below the workflow threshold', function (): void {
        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد قيد',
            'entity_type' => 'journal_entry',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);

        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 500,
            'total_credit' => 500,
        ]);
        JournalEntryLine::factory()->debit(500)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->cashAccount->id]);
        JournalEntryLine::factory()->credit(500)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->revenueAccount->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        $response->assertOk()
            ->assertJsonPath('data.status', 'posted');
    });

    it('blocks posting above threshold without an approved request', function (): void {
        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد قيد',
            'entity_type' => 'journal_entry',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);

        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 50000,
            'total_credit' => 50000,
        ]);
        JournalEntryLine::factory()->debit(50000)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->cashAccount->id]);
        JournalEntryLine::factory()->credit(50000)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->revenueAccount->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['approval']);
        expect($entry->fresh()->status)->toBe(JournalEntryStatus::Draft);
    });

    it('blocks posting when the approval request is still pending', function (): void {
        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد قيد',
            'entity_type' => 'journal_entry',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);

        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 50000,
            'total_credit' => 50000,
        ]);
        JournalEntryLine::factory()->debit(50000)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->cashAccount->id]);
        JournalEntryLine::factory()->credit(50000)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->revenueAccount->id]);

        ApprovalRequest::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $workflow->id,
            'entity_type' => 'journal_entry',
            'entity_id' => $entry->id,
            'current_step' => 1,
            'status' => ApprovalStatus::InProgress,
            'requested_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['approval']);
    });

    it('allows posting once the approval request is approved', function (): void {
        $workflow = ApprovalWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'name_ar' => 'اعتماد قيد',
            'entity_type' => 'journal_entry',
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_order' => 1,
            'approver_type' => ApproverType::User,
            'approver_id' => $this->admin->id,
            'approval_limit' => 10000,
        ]);

        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_period_id' => $this->fiscalPeriod->id,
            'status' => JournalEntryStatus::Draft,
            'total_debit' => 50000,
            'total_credit' => 50000,
        ]);
        JournalEntryLine::factory()->debit(50000)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->cashAccount->id]);
        JournalEntryLine::factory()->credit(50000)->create(['journal_entry_id' => $entry->id, 'account_id' => $this->revenueAccount->id]);

        ApprovalRequest::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $workflow->id,
            'entity_type' => 'journal_entry',
            'entity_id' => $entry->id,
            'current_step' => 1,
            'status' => ApprovalStatus::Approved,
            'requested_by' => $this->admin->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post");

        $response->assertOk()
            ->assertJsonPath('data.status', 'posted');
    });
});
