<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Services\AuditComplianceService;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    app()->instance('tenant.id', $this->tenant->id);
});

describe('High Risk Detection', function (): void {

    it('flags journal entries above threshold amount', function (): void {
        JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'total_debit' => '1000000.00',
            'total_credit' => '1000000.00',
            'created_by' => $this->admin->id,
            'date' => today(),
        ]);

        $service = app(AuditComplianceService::class);
        $result = $service->highRiskTransactions(['threshold' => '500000']);

        $largeAmountFlags = collect($result['flags'])->where('type', 'large_amount');
        expect($largeAmountFlags)->toHaveCount(1);
        expect($largeAmountFlags->first()['amount'])->toBe('1000000.00');
        expect($largeAmountFlags->first()['severity'])->toBe('high');
    });

    it('does not flag journal entries below threshold', function (): void {
        JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'total_debit' => '100000.00',
            'total_credit' => '100000.00',
            'created_by' => $this->admin->id,
            'date' => today(),
        ]);

        $service = app(AuditComplianceService::class);
        $result = $service->highRiskTransactions(['threshold' => '500000']);

        $largeAmountFlags = collect($result['flags'])->where('type', 'large_amount');
        expect($largeAmountFlags)->toHaveCount(0);
    });

    it('detects back-dated entries', function (): void {
        $entry = JournalEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'date' => today()->subDays(30),
            'created_by' => $this->admin->id,
        ]);

        $service = app(AuditComplianceService::class);
        $result = $service->highRiskTransactions([]);

        $backDatedFlags = collect($result['flags'])->where('type', 'back_dated');
        expect($backDatedFlags)->toHaveCount(1);
        expect($backDatedFlags->first()['journal_entry_id'])->toBe($entry->id);
    });

    it('detects reversed entries', function (): void {
        JournalEntry::factory()->reversed()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->admin->id,
            'reversed_by' => $this->admin->id,
        ]);

        $service = app(AuditComplianceService::class);
        $result = $service->highRiskTransactions([]);

        $reversedFlags = collect($result['flags'])->where('type', 'reversed_entry');
        expect($reversedFlags)->toHaveCount(1);
    });
});

describe('Segregation of Duties', function (): void {

    it('detects when same user created and approved a transaction', function (): void {
        JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->admin->id,
            'posted_by' => $this->admin->id,
        ]);

        $service = app(AuditComplianceService::class);
        $result = $service->segregationOfDuties([]);

        expect($result['total_violations'])->toBe(1);
        expect($result['violations'][0]['user_id'])->toBe($this->admin->id);
    });

    it('does not flag when different users created and approved', function (): void {
        $otherUser = createAdminUser($this->tenant);

        JournalEntry::factory()->posted()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->admin->id,
            'posted_by' => $otherUser->id,
        ]);

        $service = app(AuditComplianceService::class);
        $result = $service->segregationOfDuties([]);

        expect($result['total_violations'])->toBe(0);
    });
});

describe('Export Audit Trail', function (): void {

    it('generates correct JSON structure', function (): void {
        // Create some activity log entries
        activity()
            ->causedBy($this->admin)
            ->withProperties(['attributes' => ['name' => 'Test']])
            ->event('created')
            ->log('Created test entry');

        $service = app(AuditComplianceService::class);
        $result = $service->exportAuditTrail(['format' => 'json']);

        expect($result['format'])->toBe('json');
        expect($result['total'])->toBeGreaterThanOrEqual(1);
        expect($result['data'][0])->toHaveKeys([
            'id', 'timestamp', 'event', 'description',
            'model_type', 'model_id', 'user_id', 'user_name',
            'user_email', 'old_values', 'new_values',
        ]);
    });

    it('generates correct CSV structure', function (): void {
        activity()
            ->causedBy($this->admin)
            ->withProperties(['attributes' => ['name' => 'Test']])
            ->event('created')
            ->log('Created test entry');

        $service = app(AuditComplianceService::class);
        $result = $service->exportAuditTrail(['format' => 'csv']);

        expect($result['format'])->toBe('csv');
        expect($result['headers'])->toContain('id', 'timestamp', 'event', 'user_name');
        expect($result['total'])->toBeGreaterThanOrEqual(1);
    });
});

describe('API Endpoints', function (): void {

    it('requires view_audit permission', function (): void {
        // Ensure the Spatie role exists in this test DB — seeders don't
        // run by default under RefreshDatabase.
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);

        $accountant = \App\Models\User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => \App\Domain\Shared\Enums\UserRole::Accountant,
        ]);
        // Accountant role does not include view_audit
        $accountant->assignRole('accountant');
        actingAsUser($accountant);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/audit-compliance/summary');

        $response->assertForbidden();
    });

    it('allows admin to access audit compliance summary', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/audit-compliance/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'total_changes',
                    'by_model',
                    'by_user',
                    'high_risk_count',
                    'segregation_violations_count',
                ],
            ]);
    });
});
