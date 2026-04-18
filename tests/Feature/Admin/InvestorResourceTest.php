<?php

declare(strict_types=1);

use App\Domain\Investor\Enums\DistributionStatus;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorTenantShare;
use App\Domain\Investor\Models\ProfitDistribution;
use App\Filament\Admin\Resources\ProfitDistributionResource;
use App\Filament\Admin\Resources\ProfitDistributionResource\Pages\ListProfitDistributions;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->superAdmin = createSuperAdmin();
});

describe('InvestorResource', function (): void {

    it('loads the index page for SuperAdmin', function (): void {
        $this->actingAs($this->superAdmin);

        $this->get('/admin/investors')->assertOk();
    });

    it('denies non-SuperAdmin access', function (): void {
        $tenant = createTenant();
        $this->actingAs(createAdminUser($tenant));

        $this->get('/admin/investors')->assertForbidden();
    });

    it('supports creating an investor via CRUD', function (): void {
        $this->actingAs($this->superAdmin);

        $investor = Investor::create([
            'name' => 'Aya Capital',
            'email' => 'aya@example.eg',
            'join_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        expect($investor->exists)->toBeTrue()
            ->and($investor->fresh()->name)->toBe('Aya Capital');
    });

    it('persists a tenant share with ownership_percentage', function (): void {
        $investor = Investor::factory()->create();
        $tenant = createTenant();

        $share = InvestorTenantShare::create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'ownership_percentage' => 12.5,
        ]);

        expect($share->fresh()->ownership_percentage)->toBe('12.50')
            ->and($investor->fresh()->tenantShares()->count())->toBe(1);
    });
});

describe('ProfitDistributionResource', function (): void {

    it('loads the index page for SuperAdmin', function (): void {
        $this->actingAs($this->superAdmin);

        $this->get('/admin/profit-distributions')->assertOk();
    });

    it('approve action moves Draft to Approved', function (): void {
        $tenant = createTenant();
        $investor = Investor::factory()->create();
        $dist = ProfitDistribution::factory()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'status' => DistributionStatus::Draft,
        ]);

        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel('admin');

        Livewire::test(ListProfitDistributions::class)
            ->callTableAction('approve', $dist)
            ->assertHasNoTableActionErrors();

        expect($dist->fresh()->status)->toBe(DistributionStatus::Approved);
    });

    it('mark_paid action moves Approved to Paid and stamps paid_at', function (): void {
        $tenant = createTenant();
        $investor = Investor::factory()->create();
        $dist = ProfitDistribution::factory()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'status' => DistributionStatus::Approved,
        ]);

        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel('admin');

        Livewire::test(ListProfitDistributions::class)
            ->callTableAction('mark_paid', $dist)
            ->assertHasNoTableActionErrors();

        $dist->refresh();
        expect($dist->status)->toBe(DistributionStatus::Paid)
            ->and($dist->paid_at)->not->toBeNull();
    });

    it('exposes a navigation badge counting pending distributions', function (): void {
        $tenant = createTenant();
        $investor = Investor::factory()->create();

        // Unique constraint is (investor_id, tenant_id, month, year) — vary month.
        ProfitDistribution::factory()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'month' => 1,
            'year' => 2026,
            'status' => DistributionStatus::Draft,
        ]);
        ProfitDistribution::factory()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'month' => 2,
            'year' => 2026,
            'status' => DistributionStatus::Draft,
        ]);
        ProfitDistribution::factory()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'month' => 3,
            'year' => 2026,
            'status' => DistributionStatus::Approved,
        ]);
        ProfitDistribution::factory()->create([
            'investor_id' => $investor->id,
            'tenant_id' => $tenant->id,
            'month' => 4,
            'year' => 2026,
            'status' => DistributionStatus::Paid,
        ]);

        expect(ProfitDistributionResource::getNavigationBadge())->toBe('3');
    });
});
