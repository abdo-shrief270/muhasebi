<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Client\Models\Client;
use App\Domain\EInvoice\Enums\EtaDocumentStatus;
use App\Domain\EInvoice\Models\EtaDocument;
use App\Domain\EInvoice\Models\EtaItemCode;
use App\Domain\EInvoice\Models\EtaSettings;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->tenant = createTenant([
        'tax_id' => '100-200-300',
    ]);
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    $this->client = Client::factory()->create([
        'tenant_id' => $this->tenant->id,
        'tax_id' => '999-888-777',
        'name' => 'شركة العميل',
        'city' => 'القاهرة',
        'address' => 'شارع التحرير',
    ]);

    $this->settings = EtaSettings::query()->create([
        'tenant_id' => $this->tenant->id,
        'is_enabled' => true,
        'environment' => 'preprod',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'branch_id' => '0',
        'activity_code' => '4620',
        'company_trade_name' => 'شركة محاسبي',
        'branch_address_governate' => 'القاهرة',
        'branch_address_region_city' => 'القاهرة',
        'branch_address_street' => 'شارع رئيسي',
        'branch_address_building_number' => '10',
    ]);
});

function createSentInvoiceWithLines(Tenant $tenant, Client $client): Invoice
{
    $invoice = Invoice::factory()->sent()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'subtotal' => 1000.00,
        'vat_amount' => 140.00,
        'total' => 1140.00,
    ]);

    InvoiceLine::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'خدمات استشارية',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'discount_percent' => 0,
        'vat_rate' => 14,
        'line_total' => 1000.00,
        'vat_amount' => 140.00,
        'total' => 1140.00,
    ]);

    return $invoice;
}

// ──────────────────────────────────────
// Prepare
// ──────────────────────────────────────

describe('POST /api/v1/eta/documents/{invoice}/prepare', function (): void {

    it('prepares an ETA document from a sent invoice', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        $response->assertCreated()
            ->assertJsonPath('data.status', 'prepared')
            ->assertJsonPath('data.document_type', 'I')
            ->assertJsonPath('data.internal_id', $invoice->invoice_number);

        $document = EtaDocument::query()->where('invoice_id', $invoice->id)->first();
        expect($document)->not->toBeNull();
        expect($document->status)->toBe(EtaDocumentStatus::Prepared);
        expect($document->document_data)->toBeArray();
        expect($document->signed_data)->toBeString();
    });

    it('builds correct ETA JSON structure', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        $response->assertCreated();

        $document = EtaDocument::query()->where('invoice_id', $invoice->id)->first();
        $json = $document->document_data;

        // Verify top-level structure
        expect($json)->toHaveKeys([
            'issuer', 'receiver', 'documentType', 'documentTypeVersion',
            'dateTimeIssued', 'taxpayerActivityCode', 'internalID',
            'invoiceLines', 'totalSalesAmount', 'totalDiscountAmount',
            'netAmount', 'taxTotals', 'totalAmount',
        ]);

        // Verify issuer
        expect($json['issuer']['type'])->toBe('B');
        expect($json['issuer']['id'])->toBe('100-200-300');
        expect($json['issuer']['name'])->toBe('شركة محاسبي');
        expect($json['issuer']['address']['branchID'])->toBe('0');
        expect($json['issuer']['address']['country'])->toBe('EG');

        // Verify receiver
        expect($json['receiver']['type'])->toBe('B');
        expect($json['receiver']['id'])->toBe('999-888-777');
        expect($json['receiver']['name'])->toBe('شركة العميل');

        // Verify document type
        expect($json['documentType'])->toBe('I');
        expect($json['documentTypeVersion'])->toBe('1.0');
        expect($json['taxpayerActivityCode'])->toBe('4620');

        // Verify invoice lines
        expect($json['invoiceLines'])->toHaveCount(1);
        $line = $json['invoiceLines'][0];
        expect($line['description'])->toBe('خدمات استشارية');
        expect((float) $line['quantity'])->toBe(1.0);
        expect($line['unitValue']['currencySold'])->toBe('EGP');
        expect($line['taxableItems'])->toHaveCount(1);
        expect($line['taxableItems'][0]['taxType'])->toBe('T1');
    });

    it('rejects draft invoices', function (): void {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status' => InvoiceStatus::Draft,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        $response->assertUnprocessable();
    });

    it('rejects clients without tax_id', function (): void {
        $clientNoTax = Client::factory()->create([
            'tenant_id' => $this->tenant->id,
            'tax_id' => null,
        ]);

        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $clientNoTax->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        $response->assertUnprocessable();
    });

    it('rejects when ETA is not enabled', function (): void {
        $this->settings->update(['is_enabled' => false]);

        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        $response->assertUnprocessable();
    });

    it('rejects duplicate preparation for same invoice', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        // First prepare
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare")
            ->assertCreated();

        // Second prepare should fail
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        $response->assertUnprocessable();
    });

    it('allows re-preparation after rejection', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        // Create a rejected document
        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'document_type' => 'I',
            'internal_id' => $invoice->invoice_number,
            'status' => EtaDocumentStatus::Rejected,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        $response->assertCreated()
            ->assertJsonPath('data.status', 'prepared');
    });

    it('uses ETA item codes when available', function (): void {
        EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-100-200',
            'description' => 'خدمات استشارية',
            'unit_type' => 'HR',
            'default_tax_type' => 'T1',
            'default_tax_subtype' => 'V009',
        ]);

        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        $response->assertCreated();

        $document = EtaDocument::query()->where('invoice_id', $invoice->id)->first();
        $line = $document->document_data['invoiceLines'][0];

        expect($line['itemType'])->toBe('EGS');
        expect($line['itemCode'])->toBe('EG-100-200');
        expect($line['unitType'])->toBe('HR');
    });
});

// ──────────────────────────────────────
// Submit
// ──────────────────────────────────────

describe('POST /api/v1/eta/documents/{invoice}/submit', function (): void {

    it('submits a prepared document to ETA', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        // Prepare first
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        // Mock ETA API
        Http::fake([
            '*/connect/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            '*/documentsubmissions' => Http::response([
                'submissionId' => 'sub-uuid-123',
                'acceptedDocuments' => [
                    [
                        'uuid' => 'doc-uuid-456',
                        'longId' => 'long-id-789',
                        'internalId' => $invoice->invoice_number,
                    ],
                ],
                'rejectedDocuments' => [],
            ]),
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/submit");

        $response->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.eta_uuid', 'doc-uuid-456');

        $document = EtaDocument::query()->where('invoice_id', $invoice->id)->first();
        expect($document->status)->toBe(EtaDocumentStatus::Submitted);
        expect($document->eta_uuid)->toBe('doc-uuid-456');
        expect($document->eta_long_id)->toBe('long-id-789');
        expect($document->submitted_at)->not->toBeNull();
        expect($document->submission)->not->toBeNull();
        expect($document->submission->submission_uuid)->toBe('sub-uuid-123');
    });

    it('rejects submission without preparation', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/submit");

        $response->assertUnprocessable();
    });

    it('handles ETA rejection response', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/prepare");

        Http::fake([
            '*/connect/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            '*/documentsubmissions' => Http::response([
                'submissionId' => 'sub-uuid-reject',
                'acceptedDocuments' => [],
                'rejectedDocuments' => [
                    [
                        'internalId' => $invoice->invoice_number,
                        'error' => ['code' => 'INVALID_TAX_ID', 'message' => 'Invalid receiver tax ID'],
                    ],
                ],
            ]),
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/submit");

        $response->assertOk()
            ->assertJsonPath('data.status', 'invalid');

        $document = EtaDocument::query()->where('invoice_id', $invoice->id)->first();
        expect($document->status)->toBe(EtaDocumentStatus::Invalid);
        expect($document->errors)->not->toBeNull();
    });
});

// ──────────────────────────────────────
// Show & List
// ──────────────────────────────────────

describe('GET /api/v1/eta/documents/{invoice}', function (): void {

    it('shows ETA document for an invoice', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'document_type' => 'I',
            'internal_id' => $invoice->invoice_number,
            'status' => EtaDocumentStatus::Valid,
            'eta_uuid' => 'uuid-show-test',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/eta/documents/{$invoice->id}");

        $response->assertOk()
            ->assertJsonPath('data.eta_uuid', 'uuid-show-test')
            ->assertJsonPath('data.status', 'valid');
    });

    it('returns 404 when no ETA document exists', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/eta/documents/{$invoice->id}");

        $response->assertNotFound();
    });
});

describe('GET /api/v1/eta/documents', function (): void {

    it('lists all ETA documents', function (): void {
        $invoice1 = createSentInvoiceWithLines($this->tenant, $this->client);
        $invoice2 = createSentInvoiceWithLines($this->tenant, $this->client);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice1->id,
            'document_type' => 'I',
            'internal_id' => $invoice1->invoice_number,
            'status' => EtaDocumentStatus::Valid,
        ]);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice2->id,
            'document_type' => 'I',
            'internal_id' => $invoice2->invoice_number,
            'status' => EtaDocumentStatus::Submitted,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/documents');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters by status', function (): void {
        $invoice1 = createSentInvoiceWithLines($this->tenant, $this->client);
        $invoice2 = createSentInvoiceWithLines($this->tenant, $this->client);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice1->id,
            'document_type' => 'I',
            'internal_id' => $invoice1->invoice_number,
            'status' => EtaDocumentStatus::Valid,
        ]);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice2->id,
            'document_type' => 'I',
            'internal_id' => $invoice2->invoice_number,
            'status' => EtaDocumentStatus::Rejected,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/documents?status=valid');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'valid');
    });

    it('does not show documents from other tenants', function (): void {
        $otherTenant = createTenant();
        $otherInvoice = Invoice::factory()->sent()->create(['tenant_id' => $otherTenant->id]);

        EtaDocument::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'invoice_id' => $otherInvoice->id,
            'document_type' => 'I',
            'internal_id' => 'OTHER-001',
            'status' => EtaDocumentStatus::Valid,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/documents');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

// ──────────────────────────────────────
// Check Status
// ──────────────────────────────────────

describe('POST /api/v1/eta/documents/{invoice}/check-status', function (): void {

    it('updates document status from ETA', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'document_type' => 'I',
            'internal_id' => $invoice->invoice_number,
            'status' => EtaDocumentStatus::Submitted,
            'eta_uuid' => 'uuid-check-test',
        ]);

        Http::fake([
            '*/connect/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            '*/documents/uuid-check-test/raw' => Http::response([
                'uuid' => 'uuid-check-test',
                'status' => 'valid',
                'longId' => 'long-id-check',
            ]),
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/check-status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'valid');

        $document = EtaDocument::query()->where('invoice_id', $invoice->id)->first();
        expect($document->status)->toBe(EtaDocumentStatus::Valid);
        expect($document->qr_code_data)->toContain('uuid-check-test');
    });
});

// ──────────────────────────────────────
// Cancel
// ──────────────────────────────────────

describe('POST /api/v1/eta/documents/{invoice}/cancel', function (): void {

    it('cancels a valid document at ETA', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'document_type' => 'I',
            'internal_id' => $invoice->invoice_number,
            'status' => EtaDocumentStatus::Valid,
            'eta_uuid' => 'uuid-cancel-test',
        ]);

        Http::fake([
            '*/connect/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            '*/documents/state/uuid-cancel-test/state' => Http::response([
                'uuid' => 'uuid-cancel-test',
                'status' => 'cancelled',
            ]),
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/cancel", [
                'reason' => 'فاتورة خاطئة',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $document = EtaDocument::query()->where('invoice_id', $invoice->id)->first();
        expect($document->status)->toBe(EtaDocumentStatus::Cancelled);
        expect($document->cancelled_at)->not->toBeNull();
    });

    it('rejects cancellation of non-valid documents', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'document_type' => 'I',
            'internal_id' => $invoice->invoice_number,
            'status' => EtaDocumentStatus::Submitted,
            'eta_uuid' => 'uuid-nocancel',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/cancel", [
                'reason' => 'test',
            ]);

        $response->assertUnprocessable();
    });

    it('requires a reason for cancellation', function (): void {
        $invoice = createSentInvoiceWithLines($this->tenant, $this->client);

        EtaDocument::query()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'document_type' => 'I',
            'internal_id' => $invoice->invoice_number,
            'status' => EtaDocumentStatus::Valid,
            'eta_uuid' => 'uuid-noreason',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/eta/documents/{$invoice->id}/cancel", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    });
});
