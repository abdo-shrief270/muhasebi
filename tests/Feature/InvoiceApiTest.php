<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Client\Models\Client;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
});

describe('GET /api/v1/invoices', function (): void {

    it('lists invoices with pagination', function (): void {
        Invoice::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/invoices');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'invoice_number', 'type', 'date', 'due_date', 'status', 'total', 'balance_due']],
                'links',
                'meta',
            ]);
    });

    it('filters by status', function (): void {
        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status' => InvoiceStatus::Draft,
        ]);
        Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/invoices?status=draft');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'draft');
    });

    it('filters by type', function (): void {
        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'type' => InvoiceType::Invoice,
        ]);
        Invoice::factory()->creditNote()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/invoices?type=credit_note');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'credit_note');
    });

    it('filters by client_id', function (): void {
        $otherClient = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);
        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $otherClient->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/invoices?client_id={$this->client->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters by date range', function (): void {
        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-01-15',
        ]);
        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-03-15',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/invoices?from=2026-01-01&to=2026-01-31');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('does not show invoices from other tenants', function (): void {
        $otherTenant = createTenant();
        Invoice::factory()->create(['tenant_id' => $otherTenant->id]);
        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/invoices');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('POST /api/v1/invoices', function (): void {

    it('creates an invoice with lines and calculates totals correctly', function (): void {
        $data = [
            'client_id' => $this->client->id,
            'date' => '2026-03-01',
            'due_date' => '2026-03-31',
            'notes' => 'ملاحظات اختبار',
            'lines' => [
                [
                    'description' => 'خدمات استشارية',
                    'quantity' => 2,
                    'unit_price' => 1000.00,
                    'discount_percent' => 0,
                    'vat_rate' => 14,
                ],
                [
                    'description' => 'خدمات تطوير',
                    'quantity' => 1,
                    'unit_price' => 5000.00,
                    'discount_percent' => 0,
                    'vat_rate' => 14,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/invoices', $data);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.client_id', $this->client->id);

        // Subtotal = (2*1000) + (1*5000) = 7000
        // VAT = 7000 * 0.14 = 980
        // Total = 7000 + 980 = 7980
        $invoice = Invoice::query()->first();
        expect((float) $invoice->subtotal)->toBe(7000.00);
        expect((float) $invoice->vat_amount)->toBe(980.00);
        expect((float) $invoice->total)->toBe(7980.00);
    });

    it('calculates VAT at 14% standard rate', function (): void {
        $data = [
            'client_id' => $this->client->id,
            'date' => '2026-03-01',
            'lines' => [
                [
                    'description' => 'خدمة',
                    'quantity' => 1,
                    'unit_price' => 10000.00,
                    'vat_rate' => 14,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/invoices', $data);

        $response->assertCreated();

        $invoice = Invoice::query()->first();
        expect((float) $invoice->vat_amount)->toBe(1400.00);
        expect((float) $invoice->total)->toBe(11400.00);
    });

    it('calculates discount correctly', function (): void {
        $data = [
            'client_id' => $this->client->id,
            'date' => '2026-03-01',
            'lines' => [
                [
                    'description' => 'خدمة مع خصم',
                    'quantity' => 1,
                    'unit_price' => 1000.00,
                    'discount_percent' => 10,
                    'vat_rate' => 14,
                ],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/invoices', $data);

        $response->assertCreated();

        // Gross = 1000, Discount = 100, Net = 900, VAT = 900*0.14 = 126, Total = 1026
        $line = InvoiceLine::query()->first();
        expect((float) $line->line_total)->toBe(900.00);
        expect((float) $line->vat_amount)->toBe(126.00);
        expect((float) $line->total)->toBe(1026.00);
    });

    it('validates required fields', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/invoices', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'date', 'lines']);
    });

    it('validates lines are required and must have at least one', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/invoices', [
                'client_id' => $this->client->id,
                'date' => '2026-03-01',
                'lines' => [],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);
    });

    it('auto-generates invoice number', function (): void {
        $data = [
            'client_id' => $this->client->id,
            'date' => '2026-03-01',
            'lines' => [
                ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100],
            ],
        ];

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/invoices', $data);

        $response->assertCreated();

        $invoice = Invoice::query()->first();
        expect($invoice->invoice_number)->toStartWith('INV-');
    });
});

describe('GET /api/v1/invoices/{invoice}', function (): void {

    it('shows invoice with relations', function (): void {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        InvoiceLine::factory()->count(2)->create(['invoice_id' => $invoice->id]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonStructure([
                'data' => [
                    'id', 'invoice_number', 'type', 'date', 'due_date', 'status',
                    'subtotal', 'vat_amount', 'total', 'balance_due', 'client', 'lines',
                ],
            ]);
    });
});

describe('PUT /api/v1/invoices/{invoice}', function (): void {

    it('updates a draft invoice', function (): void {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'notes' => 'ملاحظات محدثة',
                'lines' => [
                    ['description' => 'بند جديد', 'quantity' => 3, 'unit_price' => 500, 'vat_rate' => 14],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.notes', 'ملاحظات محدثة');
    });

    it('cannot update a sent invoice', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'notes' => 'should fail',
            ]);

        $response->assertUnprocessable();
    });

    it('cannot update a paid invoice', function (): void {
        $invoice = Invoice::factory()->paid()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'notes' => 'should fail',
            ]);

        $response->assertUnprocessable();
    });
});

describe('DELETE /api/v1/invoices/{invoice}', function (): void {

    it('deletes a draft invoice', function (): void {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk();
        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
    });

    it('cannot delete a non-draft invoice', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/invoices/{$invoice->id}");

        $response->assertUnprocessable();
    });
});

describe('POST /api/v1/invoices/{invoice}/send', function (): void {

    it('sends a draft invoice and changes status to sent', function (): void {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/send");

        $response->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $invoice->refresh();
        expect($invoice->status)->toBe(InvoiceStatus::Sent);
        expect($invoice->sent_at)->not->toBeNull();
    });
});

describe('POST /api/v1/invoices/{invoice}/cancel', function (): void {

    it('cancels an invoice', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $invoice->refresh();
        expect($invoice->status)->toBe(InvoiceStatus::Cancelled);
        expect($invoice->cancelled_at)->not->toBeNull();
    });
});

describe('POST /api/v1/invoices/{invoice}/credit-note', function (): void {

    it('creates a credit note from an invoice', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'خدمة أصلية',
            'quantity' => 1,
            'unit_price' => 1000,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/credit-note", [
                'lines' => [
                    ['description' => 'إشعار دائن', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 14],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'credit_note')
            ->assertJsonPath('data.client_id', $this->client->id);

        $creditNote = Invoice::query()->where('type', 'credit_note')->first();
        expect($creditNote)->not->toBeNull();
        expect($creditNote->original_invoice_id)->toBe($invoice->id);
    });
});
