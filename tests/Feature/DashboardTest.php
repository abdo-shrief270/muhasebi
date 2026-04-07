<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('GET /api/v1/dashboard', function (): void {

    it('returns correct KPI structure', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'clients' => ['total', 'added_this_month'],
                    'invoices' => ['total', 'outstanding', 'outstanding_amount', 'overdue', 'overdue_amount', 'paid_this_month', 'revenue_this_month'],
                    'payments' => ['received_this_month', 'count_this_month'],
                    'journal_entries' => ['total', 'this_month'],
                    'subscription' => ['plan_name', 'status', 'trial_days_remaining'],
                    'onboarding' => ['completed', 'percent'],
                ],
            ]);
    });

    it('returns correct client count', function (): void {
        Client::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.clients.total', 3);
    });

    it('returns correct invoice stats', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        // 1 sent (outstanding)
        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'total' => 5000,
            'amount_paid' => 0,
        ]);

        // 1 paid this month
        Invoice::factory()->paid()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'total' => 3000,
            'amount_paid' => 3000,
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.invoices.total', 2)
            ->assertJsonPath('data.invoices.outstanding', 1)
            ->assertJsonPath('data.invoices.paid_this_month', 1);
    });

    it('includes onboarding status', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.onboarding.completed', false)
            ->assertJsonPath('data.onboarding.percent', 0);
    });
});
