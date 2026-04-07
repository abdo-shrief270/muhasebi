<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;
use App\Domain\TimeTracking\Models\TimesheetEntry;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
});

describe('GET /api/v1/time-billing/preview', function (): void {

    it('previews unbilled hours for a client', function (): void {
        TimesheetEntry::factory()->approved()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'date' => '2026-04-01',
            'hours' => 3,
            'hourly_rate' => 200,
            'is_billable' => true,
            'invoice_id' => null,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/time-billing/preview?client_id={$this->client->id}&date_from=2026-04-01&date_to=2026-04-30");

        $response->assertOk()
            ->assertJsonPath('data.total_hours', 6)
            ->assertJsonPath('data.total_amount', 1200)
            ->assertJsonPath('data.entry_count', 2);
    });

    it('excludes already billed entries', function (): void {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        TimesheetEntry::factory()->approved()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'date' => '2026-04-01',
            'invoice_id' => $invoice->id,
        ]);

        TimesheetEntry::factory()->approved()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'date' => '2026-04-02',
            'invoice_id' => null,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/time-billing/preview?client_id={$this->client->id}&date_from=2026-04-01&date_to=2026-04-30");

        $response->assertOk()
            ->assertJsonPath('data.entry_count', 1);
    });
});

describe('POST /api/v1/time-billing/generate', function (): void {

    it('generates invoice from approved billable entries', function (): void {
        TimesheetEntry::factory()->approved()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'date' => '2026-04-01',
            'hours' => 4,
            'hourly_rate' => 100,
            'is_billable' => true,
            'invoice_id' => null,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/time-billing/generate', [
                'client_id' => $this->client->id,
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-30',
                'vat_rate' => 14,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.client_id', $this->client->id);

        // Entries should be marked as billed
        $billedCount = TimesheetEntry::query()->whereNotNull('invoice_id')->count();
        expect($billedCount)->toBe(2);

        // Invoice should exist
        expect(Invoice::query()->count())->toBe(1);
    });

    it('rejects when no unbilled entries exist', function (): void {
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/time-billing/generate', [
                'client_id' => $this->client->id,
                'date_from' => '2026-04-01',
                'date_to' => '2026-04-30',
            ])
            ->assertUnprocessable();
    });
});
