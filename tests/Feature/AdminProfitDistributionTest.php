<?php

declare(strict_types=1);

use App\Domain\Investor\Enums\DistributionStatus;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorTenantShare;
use App\Domain\Investor\Models\ProfitDistribution;
use App\Domain\Subscription\Models\SubscriptionPayment;

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

        // Distributions span both tenants; count across them, not just the
        // currently bound one.
        expect(ProfitDistribution::query()->withoutGlobalScope('tenant')->count())->toBe(2);
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

describe('Distribution monetary precision', function (): void {

    it('produces shares summing to the distributable profit with fractional ownership', function (): void {
        // Three investors splitting 33.33 / 33.33 / 33.34 of the same tenant's profit.
        // With naive float arithmetic, the sum of shares can drift by 0.01.
        $tenant = createTenant();

        $investorA = Investor::factory()->create();
        $investorB = Investor::factory()->create();
        $investorC = Investor::factory()->create();

        foreach ([[$investorA, '33.33'], [$investorB, '33.33'], [$investorC, '33.34']] as [$investor, $pct]) {
            InvestorTenantShare::query()->create([
                'investor_id' => $investor->id,
                'tenant_id' => $tenant->id,
                'ownership_percentage' => $pct,
            ]);
        }

        // Revenue 1000.00, expenses 0, net profit 1000.00
        SubscriptionPayment::factory()->completed()->create([
            'tenant_id' => $tenant->id,
            'amount' => '1000.00',
            'paid_at' => '2026-04-10 12:00:00',
        ]);

        $response = $this->postJson('/api/v1/admin/distributions/calculate', [
            'month' => 4,
            'year' => 2026,
        ]);

        $response->assertCreated();

        $distributions = ProfitDistribution::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('month', 4)
            ->where('year', 2026)
            ->get();

        expect($distributions)->toHaveCount(3);

        $sum = $distributions->reduce(
            fn (string $carry, ProfitDistribution $d) => bcadd($carry, (string) $d->investor_share, 2),
            '0.00',
        );

        // Shares at 33.33/33.33/33.34 of 1000.00 sum exactly to 1000.00.
        expect($sum)->toBe('1000.00');

        // Each individual share is rounded correctly, not truncated.
        $byInvestor = $distributions->keyBy('investor_id');
        expect((string) $byInvestor[$investorA->id]->investor_share)->toBe('333.30');
        expect((string) $byInvestor[$investorB->id]->investor_share)->toBe('333.30');
        expect((string) $byInvestor[$investorC->id]->investor_share)->toBe('333.40');
    });

    it('treats losses as zero distributable profit', function (): void {
        $tenant = createTenant();
        $investor = Investor::factory()->create();

        InvestorTenantShare::query()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'ownership_percentage' => '50',
        ]);

        // Revenue 100, expenses 500 -> net profit -400 (a loss)
        SubscriptionPayment::factory()->completed()->create([
            'tenant_id' => $tenant->id,
            'amount' => '100.00',
            'paid_at' => '2026-05-10 12:00:00',
        ]);

        $response = $this->postJson('/api/v1/admin/distributions/calculate', [
            'month' => 5,
            'year' => 2026,
            'expenses' => [
                ['tenant_id' => $tenant->id, 'amount' => '500.00'],
            ],
        ]);

        $response->assertCreated();

        $dist = ProfitDistribution::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('investor_id', $investor->id)
            ->firstOrFail();

        // Loss is recorded truthfully but the investor share is floored at zero.
        expect((string) $dist->net_profit)->toBe('-400.00');
        expect((string) $dist->investor_share)->toBe('0.00');
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
