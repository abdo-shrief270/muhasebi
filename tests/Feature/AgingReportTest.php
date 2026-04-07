<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Client\Models\Client;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    // Create required accounts
    Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1121',
        'name_ar' => 'المدينون',
        'name_en' => 'Accounts Receivable',
    ]);

    Account::factory()->revenue()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '4110',
        'name_ar' => 'الإيرادات',
        'name_en' => 'Revenue',
    ]);

    Account::factory()->liability()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '2131',
        'name_ar' => 'ضريبة القيمة المضافة',
        'name_en' => 'VAT Payable',
    ]);

    Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1111',
        'name_ar' => 'النقدية',
        'name_en' => 'Cash',
    ]);

    Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1112',
        'name_ar' => 'البنك',
        'name_en' => 'Bank',
    ]);
});

describe('GET /api/v1/reports/aging', function (): void {

    it('returns empty aging when there are no invoices', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/aging');

        $response->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('totals.total', '0.00');
    });

    it('shows current invoice (not yet due)', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'date' => today()->subDays(5),
            'due_date' => today()->addDays(25),
            'total' => 5000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/aging');

        $response->assertOk();

        $rows = $response->json('rows');
        expect($rows)->toHaveCount(1);
        expect($rows[0]['current'])->toBe('5000.00');
        expect($rows[0]['total'])->toBe('5000.00');
    });

    it('shows 30-day overdue invoice', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'date' => today()->subDays(45),
            'due_date' => today()->subDays(15),
            'total' => 3000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/aging');

        $response->assertOk();

        $rows = $response->json('rows');
        expect($rows)->toHaveCount(1);
        expect($rows[0]['days_1_30'])->toBe('3000.00');
    });

    it('shows 90-day overdue invoice', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'date' => today()->subDays(120),
            'due_date' => today()->subDays(75),
            'total' => 8000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/aging');

        $response->assertOk();

        $rows = $response->json('rows');
        expect($rows)->toHaveCount(1);
        expect($rows[0]['days_61_90'])->toBe('8000.00');
    });

    it('groups multiple clients in aging report', function (): void {
        $client1 = Client::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'شركة أ']);
        $client2 = Client::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'شركة ب']);

        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client1->id,
            'due_date' => today()->addDays(10),
            'total' => 2000.00,
            'amount_paid' => 0,
        ]);

        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client2->id,
            'due_date' => today()->subDays(10),
            'total' => 4000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/reports/aging');

        $response->assertOk();

        $rows = $response->json('rows');
        expect($rows)->toHaveCount(2);

        $totals = $response->json('totals');
        expect($totals['total'])->toBe('6000.00');
    });

    it('filters aging report by client_id', function (): void {
        $client1 = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $client2 = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client1->id,
            'total' => 2000.00,
            'amount_paid' => 0,
        ]);

        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client2->id,
            'total' => 4000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/reports/aging?client_id={$client1->id}");

        $response->assertOk();

        $rows = $response->json('rows');
        expect($rows)->toHaveCount(1);
        expect($rows[0]['client_id'])->toBe($client1->id);
    });
});

describe('GET /api/v1/reports/clients/{client}/statement', function (): void {

    it('shows client statement with running balance', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'date' => '2026-03-01',
            'due_date' => '2026-03-31',
            'total' => 10000.00,
            'amount_paid' => 3000.00,
            'status' => InvoiceStatus::PartiallyPaid,
        ]);

        Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'amount' => 3000.00,
            'date' => '2026-03-10',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/reports/clients/{$client->id}/statement?from=2026-03-01&to=2026-03-31");

        $response->assertOk()
            ->assertJsonStructure([
                'opening_balance',
                'transactions',
                'closing_balance',
            ]);

        $data = $response->json();
        expect($data['transactions'])->not->toBeEmpty();
        expect($data['closing_balance'])->not->toBeNull();
    });

    it('returns empty statement for client with no invoices', function (): void {
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/reports/clients/{$client->id}/statement?from=2026-03-01&to=2026-03-31");

        $response->assertOk()
            ->assertJsonPath('opening_balance', '0.00')
            ->assertJsonPath('transactions', [])
            ->assertJsonPath('closing_balance', '0.00');
    });
});
