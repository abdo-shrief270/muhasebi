<?php

declare(strict_types=1);

use App\Domain\ECommerce\Enums\ECommercePlatform;
use App\Domain\ECommerce\Enums\OrderSyncStatus;
use App\Domain\ECommerce\Models\ECommerceChannel;
use App\Domain\ECommerce\Models\ECommerceOrder;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

// ── Enum Labels ──

describe('ECommercePlatform Enum', function (): void {

    it('returns English labels for all platforms', function (): void {
        expect(ECommercePlatform::Shopify->label())->toBe('Shopify');
        expect(ECommercePlatform::WooCommerce->label())->toBe('WooCommerce');
        expect(ECommercePlatform::Salla->label())->toBe('Salla');
        expect(ECommercePlatform::Zid->label())->toBe('Zid');
        expect(ECommercePlatform::Custom->label())->toBe('Custom');
    });

    it('returns Arabic labels for all platforms', function (): void {
        expect(ECommercePlatform::Shopify->labelAr())->toBe('شوبيفاي');
        expect(ECommercePlatform::WooCommerce->labelAr())->toBe('ووكومرس');
        expect(ECommercePlatform::Salla->labelAr())->toBe('سلة');
        expect(ECommercePlatform::Zid->labelAr())->toBe('زد');
        expect(ECommercePlatform::Custom->labelAr())->toBe('مخصص');
    });

});

describe('OrderSyncStatus Enum', function (): void {

    it('returns labels for all statuses', function (): void {
        expect(OrderSyncStatus::Pending->label())->toBe('Pending');
        expect(OrderSyncStatus::Synced->label())->toBe('Synced');
        expect(OrderSyncStatus::Failed->label())->toBe('Failed');
        expect(OrderSyncStatus::Skipped->label())->toBe('Skipped');
    });

});

// ── Channel CRUD ──

describe('GET /api/v1/ecommerce/channels', function (): void {

    it('lists channels for the tenant', function (): void {
        ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'My Shopify Store',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/ecommerce/channels');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My Shopify Store');
    });

    it('does not show channels from other tenants', function (): void {
        $otherTenant = createTenant();
        ECommerceChannel::create([
            'tenant_id' => $otherTenant->id,
            'platform' => 'salla',
            'name' => 'Other Store',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/ecommerce/channels');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

});

describe('POST /api/v1/ecommerce/channels', function (): void {

    it('creates a channel', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/ecommerce/channels', [
                'platform' => 'woocommerce',
                'name' => 'WooCommerce Store',
                'api_url' => 'https://store.example.com',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.platform', 'woocommerce')
            ->assertJsonPath('data.name', 'WooCommerce Store');

        $this->assertDatabaseHas('ecommerce_channels', [
            'tenant_id' => $this->tenant->id,
            'platform' => 'woocommerce',
            'name' => 'WooCommerce Store',
        ]);
    });

    it('validates required fields', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/ecommerce/channels', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['platform', 'name']);
    });

});

describe('PUT /api/v1/ecommerce/channels/{channel}', function (): void {

    it('updates a channel', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Old Name',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->putJson("/api/v1/ecommerce/channels/{$channel->id}", [
                'name' => 'New Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    });

});

describe('DELETE /api/v1/ecommerce/channels/{channel}', function (): void {

    it('soft-deletes a channel', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'zid',
            'name' => 'Zid Store',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/ecommerce/channels/{$channel->id}");

        $response->assertOk();
        $this->assertSoftDeleted('ecommerce_channels', ['id' => $channel->id]);
    });

});

// ── Order to Invoice ──

describe('POST /api/v1/ecommerce/orders/{order}/convert', function (): void {

    it('converts an order to an invoice with correct totals', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Test Store',
        ]);

        $order = ECommerceOrder::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'external_order_id' => 'EXT-001',
            'order_number' => 'ORD-001',
            'status' => 'pending',
            'customer_name' => 'Ahmed Hassan',
            'customer_email' => 'ahmed@example.com',
            'total' => 10000.00,
            'currency' => 'EGP',
            'tax_amount' => 1400.00,
            'shipping_amount' => 0,
            'items' => [
                [
                    'name' => 'Product A',
                    'quantity' => 2,
                    'unit_price' => 5000,
                    'vat_rate' => 14,
                ],
            ],
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/ecommerce/orders/{$order->id}/convert");

        $response->assertOk()
            ->assertJsonPath('data.status', 'synced')
            ->assertJsonPath('message', 'Order converted to invoice.');

        $order->refresh();
        expect($order->synced_invoice_id)->not->toBeNull();
        expect($order->synced_at)->not->toBeNull();

        // Verify the invoice total matches the order — 10000 → 10000
        $invoice = $order->syncedInvoice;
        expect($invoice)->not->toBeNull();
        expect((float) $invoice->total)->toBe(10000.00);
    });

});

// ── Dashboard ──

describe('GET /api/v1/ecommerce/dashboard', function (): void {

    it('returns dashboard stats per channel', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'salla',
            'name' => 'Salla Store',
        ]);

        ECommerceOrder::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'external_order_id' => 'EXT-100',
            'order_number' => 'ORD-100',
            'status' => 'pending',
            'total' => 500.00,
            'currency' => 'EGP',
            'tax_amount' => 70.00,
            'shipping_amount' => 0,
        ]);

        ECommerceOrder::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'external_order_id' => 'EXT-101',
            'order_number' => 'ORD-101',
            'status' => 'synced',
            'total' => 1500.00,
            'currency' => 'EGP',
            'tax_amount' => 210.00,
            'shipping_amount' => 50.00,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/ecommerce/dashboard');

        $response->assertOk()
            ->assertJsonPath('summary.total_channels', 1)
            ->assertJsonPath('summary.active_channels', 1)
            ->assertJsonPath('summary.total_orders', 2)
            ->assertJsonPath('summary.pending_orders', 1)
            ->assertJsonPath('summary.synced_orders', 1)
            ->assertJsonPath('summary.total_revenue', '2000.00')
            ->assertJsonPath('summary.currency', 'EGP');
    });

});
