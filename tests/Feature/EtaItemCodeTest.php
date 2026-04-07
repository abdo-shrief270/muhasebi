<?php

declare(strict_types=1);

use App\Domain\EInvoice\Models\EtaItemCode;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('GET /api/v1/eta/item-codes', function (): void {

    it('lists item codes with pagination', function (): void {
        EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-001-001',
            'description' => 'Consulting services',
            'description_ar' => 'خدمات استشارية',
        ]);

        EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'GS1',
            'item_code' => '1234567890123',
            'description' => 'Product A',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/item-codes');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'code_type', 'item_code', 'description', 'unit_type', 'is_active']],
            ]);
    });

    it('filters by search term', function (): void {
        EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-001-001',
            'description' => 'Consulting services',
        ]);

        EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-002-001',
            'description' => 'Accounting services',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/item-codes?search=Consulting');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_code', 'EG-001-001');
    });

    it('does not show item codes from other tenants', function (): void {
        $otherTenant = createTenant();
        EtaItemCode::query()->create([
            'tenant_id' => $otherTenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-999-999',
            'description' => 'Other tenant code',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/eta/item-codes');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('POST /api/v1/eta/item-codes', function (): void {

    it('creates an item code', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/eta/item-codes', [
                'code_type' => 'EGS',
                'item_code' => 'EG-100-200',
                'description' => 'Tax consulting',
                'description_ar' => 'استشارات ضريبية',
                'unit_type' => 'EA',
                'default_tax_type' => 'T1',
                'default_tax_subtype' => 'V009',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.code_type', 'EGS')
            ->assertJsonPath('data.item_code', 'EG-100-200')
            ->assertJsonPath('data.description', 'Tax consulting')
            ->assertJsonPath('data.unit_type', 'EA');
    });

    it('validates required fields', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/eta/item-codes', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code_type', 'item_code', 'description']);
    });

    it('validates code_type must be GS1 or EGS', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/eta/item-codes', [
                'code_type' => 'INVALID',
                'item_code' => 'X-001',
                'description' => 'test',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code_type']);
    });

    it('prevents duplicate item codes within same tenant', function (): void {
        EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-001-001',
            'description' => 'Existing',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/eta/item-codes', [
                'code_type' => 'EGS',
                'item_code' => 'EG-001-001',
                'description' => 'Duplicate',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['item_code']);
    });
});

describe('PUT /api/v1/eta/item-codes/{itemCode}', function (): void {

    it('updates an item code', function (): void {
        $itemCode = EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-001-001',
            'description' => 'Original',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/eta/item-codes/{$itemCode->id}", [
                'description' => 'Updated description',
                'is_active' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.is_active', false);
    });
});

describe('DELETE /api/v1/eta/item-codes/{itemCode}', function (): void {

    it('deletes an item code', function (): void {
        $itemCode = EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-DEL-001',
            'description' => 'To delete',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/eta/item-codes/{$itemCode->id}");

        $response->assertOk();
        expect(EtaItemCode::query()->find($itemCode->id))->toBeNull();
    });
});
