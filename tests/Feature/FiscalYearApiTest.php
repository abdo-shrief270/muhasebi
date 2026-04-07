<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('POST /api/v1/fiscal-years', function (): void {

    it('creates a fiscal year and auto-generates 12 monthly periods', function (): void {
        $data = [
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/fiscal-years', $data);

        $response->assertCreated()
            ->assertJsonPath('data.name', '2026')
            ->assertJsonPath('data.is_closed', false);

        // Should have 12 periods
        $yearId = $response->json('data.id');
        $periodsCount = FiscalPeriod::query()
            ->withoutGlobalScopes()
            ->where('fiscal_year_id', $yearId)
            ->count();

        expect($periodsCount)->toBe(12);
    });

    it('cannot create overlapping fiscal years', function (): void {
        FiscalYear::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $data = [
            'name' => '2026 Duplicate',
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/fiscal-years', $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);
    });
});

describe('GET /api/v1/fiscal-years', function (): void {

    it('lists fiscal years', function (): void {
        FiscalYear::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/fiscal-years');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

describe('GET /api/v1/fiscal-years/{fiscalYear}', function (): void {

    it('shows fiscal year with periods', function (): void {
        $year = FiscalYear::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        foreach ([1, 2, 3] as $i) {
            FiscalPeriod::factory()->create([
                'tenant_id' => $this->tenant->id,
                'fiscal_year_id' => $year->id,
                'period_number' => $i,
                'name' => "Period {$i}",
            ]);
        }

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/fiscal-years/{$year->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $year->id)
            ->assertJsonCount(3, 'data.periods');
    });
});

describe('POST /api/v1/fiscal-periods/{fiscalPeriod}/close', function (): void {

    it('closes a period', function (): void {
        $year = FiscalYear::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $period = FiscalPeriod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 1,
            'is_closed' => false,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/fiscal-periods/{$period->id}/close");

        $response->assertOk()
            ->assertJsonPath('data.is_closed', true);

        expect($response->json('data.closed_at'))->not->toBeNull();
    });

    it('cannot close period if previous period is open', function (): void {
        $year = FiscalYear::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $period1 = FiscalPeriod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 1,
            'is_closed' => false,
        ]);

        $period2 = FiscalPeriod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 2,
            'is_closed' => false,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/fiscal-periods/{$period2->id}/close");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    });
});

describe('POST /api/v1/fiscal-periods/{fiscalPeriod}/reopen', function (): void {

    it('reopens the last closed period', function (): void {
        $year = FiscalYear::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $period1 = FiscalPeriod::factory()->closed()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 1,
            'closed_by' => $this->admin->id,
        ]);

        $period2 = FiscalPeriod::factory()->closed()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 2,
            'closed_by' => $this->admin->id,
        ]);

        // Reopen period 2 (the last closed one) — should succeed
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/fiscal-periods/{$period2->id}/reopen");

        $response->assertOk()
            ->assertJsonPath('data.is_closed', false);

        // Reopen period 1 should also succeed now since period 2 is open
        $response2 = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/fiscal-periods/{$period1->id}/reopen");

        $response2->assertOk()
            ->assertJsonPath('data.is_closed', false);
    });

    it('cannot reopen a period if a subsequent period is closed', function (): void {
        $year = FiscalYear::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $period1 = FiscalPeriod::factory()->closed()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 1,
            'closed_by' => $this->admin->id,
        ]);

        $period2 = FiscalPeriod::factory()->closed()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 2,
            'closed_by' => $this->admin->id,
        ]);

        // Trying to reopen period 1 while period 2 is still closed should fail
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/fiscal-periods/{$period1->id}/reopen");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    });
});
