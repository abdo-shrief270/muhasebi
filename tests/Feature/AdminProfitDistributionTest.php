<?php

declare(strict_types=1);

use App\Domain\Investor\Enums\DistributionStatus;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorTenantShare;
use App\Domain\Investor\Models\ProfitDistribution;

beforeEach(function (): void {
    $this->superAdmin = createSuperAdmin();
    actingAsUser($this->superAdmin);
});

describe('POST /api/v1/admin/distributions/calculate', function (): void {

    it('calculates distributions for all active investors per tenant', function (): void {
        $tenant1 = createTenant();
        $tenant2 = createTenant();
        $investor = Investor::factory()->create();

        InvestorTenantShare::query()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant1->id,
            'ownership_percentage' => 30,
        ]);
        InvestorTenantShare::query()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant2->id,
            'ownership_percentage' => 50,
        ]);

        $response = $this->postJson('/api/v1/admin/distributions/calculate', [
            'month' => 4,
            'year' => 2026,
            'expenses' => [
                ['tenant_id' => $tenant1->id, 'amount' => 1000],
                ['tenant_id' => $tenant2->id, 'amount' => 2000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('count', 2);

        expect(ProfitDistribution::query()->count())->toBe(2);
    });

    it('skips inactive investors', function (): void {
        $tenant = createTenant();
        $activeInvestor = Investor::factory()->create(['is_active' => true]);
        $inactiveInvestor = Investor::factory()->inactive()->create();

        InvestorTenantShare::query()->create([
            'investor_id' => $activeInvestor->id,
            'tenant_id' => $tenant->id,
            'ownership_percentage' => 30,
        ]);
        InvestorTenantShare::query()->create([
            'investor_id' => $inactiveInvestor->id,
            'tenant_id' => $tenant->id,
            'ownership_percentage' => 20,
        ]);

        $response = $this->postJson('/api/v1/admin/distributions/calculate', [
            'month' => 4,
            'year' => 2026,
        ]);

        $response->assertCreated()
            ->assertJsonPath('count', 1);
    });
});

describe('Distribution lifecycle', function (): void {

    it('approves a draft distribution', function (): void {
        $dist = ProfitDistribution::factory()->create();

        $response = $this->postJson("/api/v1/admin/distributions/{$dist->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved');
    });

    it('marks an approved distribution as paid', function (): void {
        $dist = ProfitDistribution::factory()->approved()->create();

        $response = $this->postJson("/api/v1/admin/distributions/{$dist->id}/mark-paid");

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid');
    });

    it('prevents approving a non-draft distribution', function (): void {
        $dist = ProfitDistribution::factory()->approved()->create();

        $this->postJson("/api/v1/admin/distributions/{$dist->id}/approve")
            ->assertUnprocessable();
    });

    it('prevents deleting a non-draft distribution', function (): void {
        $dist = ProfitDistribution::factory()->approved()->create();

        $this->deleteJson("/api/v1/admin/distributions/{$dist->id}")
            ->assertUnprocessable();
    });

    it('deletes a draft distribution', function (): void {
        $dist = ProfitDistribution::factory()->create();

        $this->deleteJson("/api/v1/admin/distributions/{$dist->id}")
            ->assertOk();
    });
});

describe('GET /api/v1/admin/distributions', function (): void {

    it('lists distributions with filters', function (): void {
        ProfitDistribution::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/admin/distributions');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters by status', function (): void {
        ProfitDistribution::factory()->create(['status' => DistributionStatus::Draft]);
        ProfitDistribution::factory()->approved()->create();

        $response = $this->getJson('/api/v1/admin/distributions?status=draft');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});
