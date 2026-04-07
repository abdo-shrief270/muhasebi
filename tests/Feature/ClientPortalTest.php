<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Client\Models\Client;
use App\Domain\Document\Models\Document;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->clientUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->client->id,
        'role' => UserRole::Client,
    ]);
    actingAsUser($this->clientUser);
});

describe('Client Portal Middleware', function (): void {

    it('blocks non-client users', function (): void {
        $admin = createAdminUser($this->tenant);
        actingAsUser($admin);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/dashboard')
            ->assertForbidden();
    });

    it('blocks client users without client_id', function (): void {
        $noClientUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => null,
            'role' => UserRole::Client,
        ]);
        actingAsUser($noClientUser);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/dashboard')
            ->assertForbidden();
    });

    it('allows valid client users', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/dashboard')
            ->assertOk();
    });
});

describe('GET /api/v1/portal/dashboard', function (): void {

    it('returns dashboard KPIs', function (): void {
        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'total' => 5000,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'outstanding_balance',
                    'overdue_invoices_count',
                    'recent_invoices',
                    'recent_documents',
                    'unread_notifications_count',
                ],
            ]);
    });
});

describe('GET /api/v1/portal/profile', function (): void {

    it('returns client profile', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/profile');

        $response->assertOk()
            ->assertJsonPath('data.id', $this->client->id);
    });
});

describe('GET /api/v1/portal/invoices', function (): void {

    it('lists non-draft invoices for this client', function (): void {
        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);
        // Draft should not appear
        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/invoices');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('does not show other clients invoices', function (): void {
        $otherClient = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $otherClient->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/invoices');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('GET /api/v1/portal/invoices/{invoice}', function (): void {

    it('shows invoice detail', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);
        InvoiceLine::factory()->create(['invoice_id' => $invoice->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/portal/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonStructure(['data' => ['lines', 'payments']]);
    });

    it('returns 403 for other clients invoice', function (): void {
        $otherClient = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $otherClient->id,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/portal/invoices/{$invoice->id}")
            ->assertForbidden();
    });
});
