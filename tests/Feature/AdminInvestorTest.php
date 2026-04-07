<?php

declare(strict_types=1);

use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorTenantShare;

beforeEach(function (): void {
    $this->superAdmin = createSuperAdmin();
    actingAsUser($this->superAdmin);
});

describe('Investor CRUD', function (): void {

    it('creates an investor', function (): void {
        $response = $this->postJson('/api/v1/admin/investors', [
            'name' => 'أحمد المستثمر',
            'email' => 'investor@example.com',
            'join_date' => '2026-01-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'أحمد المستثمر');
    });

    it('lists investors', function (): void {
        Investor::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/admin/investors');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('updates an investor', function (): void {
        $investor = Investor::factory()->create();

        $response = $this->putJson("/api/v1/admin/investors/{$investor->id}", [
            'name' => 'اسم محدث',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'اسم محدث');
    });

    it('deletes an investor', function (): void {
        $investor = Investor::factory()->create();

        $this->deleteJson("/api/v1/admin/investors/{$investor->id}")
            ->assertOk();

        $this->assertSoftDeleted('investors', ['id' => $investor->id]);
    });
});

describe('Tenant Shares', function (): void {

    it('sets a share for a tenant', function (): void {
        $investor = Investor::factory()->create();
        $tenant = createTenant();

        $response = $this->postJson("/api/v1/admin/investors/{$investor->id}/shares", [
            'tenant_id' => $tenant->id,
            'ownership_percentage' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.ownership_percentage', '30.00');
    });

    it('lists shares for an investor', function (): void {
        $investor = Investor::factory()->create();
        $tenant1 = createTenant();
        $tenant2 = createTenant();

        InvestorTenantShare::query()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant1->id,
            'ownership_percentage' => 25,
        ]);
        InvestorTenantShare::query()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant2->id,
            'ownership_percentage' => 40,
        ]);

        $response = $this->getJson("/api/v1/admin/investors/{$investor->id}/shares");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('prevents ownership exceeding 100% per tenant', function (): void {
        $tenant = createTenant();
        $investor1 = Investor::factory()->create();
        $investor2 = Investor::factory()->create();

        InvestorTenantShare::query()->create([
            'investor_id' => $investor1->id,
            'tenant_id' => $tenant->id,
            'ownership_percentage' => 80,
        ]);

        $response = $this->postJson("/api/v1/admin/investors/{$investor2->id}/shares", [
            'tenant_id' => $tenant->id,
            'ownership_percentage' => 25,
        ]);

        $response->assertUnprocessable();
    });

    it('removes a share', function (): void {
        $investor = Investor::factory()->create();
        $tenant = createTenant();

        InvestorTenantShare::query()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'ownership_percentage' => 30,
        ]);

        $this->deleteJson("/api/v1/admin/investors/{$investor->id}/shares/{$tenant->id}")
            ->assertOk();

        expect(InvestorTenantShare::query()->count())->toBe(0);
    });
});
