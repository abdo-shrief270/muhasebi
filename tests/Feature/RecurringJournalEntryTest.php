<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\RecurringFrequency;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\RecurringJournalEntry;
use App\Domain\Accounting\Services\RecurringJournalEntryService;
use Carbon\Carbon;

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

    $this->fiscalPeriod = FiscalPeriod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_year_id' => $this->fiscalYear->id,
        'name' => 'April 2026',
        'period_number' => 4,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);

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

    $this->balancedLines = [
        ['account_id' => $this->cashAccount->id, 'debit' => 1000, 'credit' => 0, 'description' => 'Cash'],
        ['account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 1000, 'description' => 'Revenue'],
    ];
});

// ── RecurringFrequency Enum ──────────────────────────────────────────

describe('RecurringFrequency', function (): void {

    it('calculates monthly nextDate correctly', function (): void {
        $from = Carbon::parse('2026-01-15');
        $next = RecurringFrequency::Monthly->nextDate($from);

        expect($next->toDateString())->toBe('2026-02-15');
    });

    it('calculates daily nextDate correctly', function (): void {
        $from = Carbon::parse('2026-04-07');
        $next = RecurringFrequency::Daily->nextDate($from);

        expect($next->toDateString())->toBe('2026-04-08');
    });

    it('calculates quarterly nextDate correctly', function (): void {
        $from = Carbon::parse('2026-01-01');
        $next = RecurringFrequency::Quarterly->nextDate($from);

        expect($next->toDateString())->toBe('2026-04-01');
    });

    it('calculates annually nextDate correctly', function (): void {
        $from = Carbon::parse('2026-04-07');
        $next = RecurringFrequency::Annually->nextDate($from);

        expect($next->toDateString())->toBe('2027-04-07');
    });

    it('returns correct labels', function (): void {
        expect(RecurringFrequency::Monthly->label())->toBe('Monthly')
            ->and(RecurringFrequency::Monthly->labelAr())->toBe('شهري')
            ->and(RecurringFrequency::Daily->label())->toBe('Daily')
            ->and(RecurringFrequency::Weekly->label())->toBe('Weekly')
            ->and(RecurringFrequency::Quarterly->label())->toBe('Quarterly')
            ->and(RecurringFrequency::Annually->label())->toBe('Annually');
    });
});

// ── Validation ───────────────────────────────────────────────────────

describe('Template lines validation', function (): void {

    it('rejects unbalanced lines', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/recurring-journal-entries', [
                'template_name_ar' => 'قيد غير متوازن',
                'frequency' => RecurringFrequency::Monthly->value,
                'next_run_date' => now()->addDay()->toDateString(),
                'lines' => [
                    ['account_id' => $this->cashAccount->id, 'debit' => 1000, 'credit' => 0],
                    ['account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 500],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
    });

    it('rejects a line with both debit and credit', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/recurring-journal-entries', [
                'template_name_ar' => 'قيد خاطئ',
                'frequency' => RecurringFrequency::Monthly->value,
                'next_run_date' => now()->addDay()->toDateString(),
                'lines' => [
                    ['account_id' => $this->cashAccount->id, 'debit' => 500, 'credit' => 500],
                    ['account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 1000],
                ],
            ]);

        $response->assertUnprocessable();
    });

    it('requires at least 2 lines', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/recurring-journal-entries', [
                'template_name_ar' => 'قيد سطر واحد',
                'frequency' => RecurringFrequency::Monthly->value,
                'next_run_date' => now()->addDay()->toDateString(),
                'lines' => [
                    ['account_id' => $this->cashAccount->id, 'debit' => 1000, 'credit' => 0],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
    });
});

// ── processDue ───────────────────────────────────────────────────────

describe('processDue', function (): void {

    it('increments run_count and advances next_run_date', function (): void {
        $recurring = RecurringJournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'template_name_ar' => 'إيجار شهري',
            'frequency' => RecurringFrequency::Monthly,
            'lines' => $this->balancedLines,
            'next_run_date' => now()->subDay()->toDateString(),
            'is_active' => true,
            'run_count' => 0,
            'created_by' => $this->admin->id,
        ]);

        $service = app(RecurringJournalEntryService::class);
        $processed = $service->processDue();

        expect($processed)->toBe(1);

        $recurring->refresh();
        expect($recurring->run_count)->toBe(1)
            ->and($recurring->last_run_date->toDateString())->toBe(now()->toDateString())
            ->and($recurring->next_run_date->toDateString())
            ->toBe(RecurringFrequency::Monthly->nextDate($recurring->last_run_date->subDay())->toDateString());
    });

    it('skips inactive templates', function (): void {
        RecurringJournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'template_name_ar' => 'غير نشط',
            'frequency' => RecurringFrequency::Monthly,
            'lines' => $this->balancedLines,
            'next_run_date' => now()->subDay()->toDateString(),
            'is_active' => false,
            'run_count' => 0,
        ]);

        $service = app(RecurringJournalEntryService::class);
        $processed = $service->processDue();

        expect($processed)->toBe(0);
    });

    it('skips templates with future next_run_date', function (): void {
        RecurringJournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'template_name_ar' => 'مستقبلي',
            'frequency' => RecurringFrequency::Monthly,
            'lines' => $this->balancedLines,
            'next_run_date' => now()->addWeek()->toDateString(),
            'is_active' => true,
            'run_count' => 0,
        ]);

        $service = app(RecurringJournalEntryService::class);
        $processed = $service->processDue();

        expect($processed)->toBe(0);
    });

    it('skips templates past their end_date', function (): void {
        RecurringJournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'template_name_ar' => 'منتهي',
            'frequency' => RecurringFrequency::Monthly,
            'lines' => $this->balancedLines,
            'next_run_date' => now()->subDay()->toDateString(),
            'end_date' => now()->subWeek()->toDateString(),
            'is_active' => true,
            'run_count' => 0,
        ]);

        $service = app(RecurringJournalEntryService::class);
        $processed = $service->processDue();

        expect($processed)->toBe(0);
    });
});

// ── CRUD API ─────────────────────────────────────────────────────────

describe('CRUD API', function (): void {

    it('creates a recurring journal entry', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/recurring-journal-entries', [
                'template_name_ar' => 'إيجار شهري',
                'template_name_en' => 'Monthly Rent',
                'frequency' => RecurringFrequency::Monthly->value,
                'next_run_date' => now()->addDay()->toDateString(),
                'lines' => $this->balancedLines,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.template_name_ar', 'إيجار شهري')
            ->assertJsonPath('data.frequency', RecurringFrequency::Monthly->value);
    });

    it('lists recurring journal entries', function (): void {
        RecurringJournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'template_name_ar' => 'قيد 1',
            'frequency' => RecurringFrequency::Monthly,
            'lines' => $this->balancedLines,
            'next_run_date' => now()->addDay()->toDateString(),
            'is_active' => true,
            'run_count' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/recurring-journal-entries');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('toggles active state', function (): void {
        $recurring = RecurringJournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'template_name_ar' => 'قيد',
            'frequency' => RecurringFrequency::Weekly,
            'lines' => $this->balancedLines,
            'next_run_date' => now()->addDay()->toDateString(),
            'is_active' => true,
            'run_count' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/recurring-journal-entries/{$recurring->id}/toggle");

        $response->assertOk()
            ->assertJsonPath('data.is_active', false);
    });
});
