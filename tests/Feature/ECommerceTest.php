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

// ── Bulk Convert Status Codes ──

describe('POST /api/v1/ecommerce/bulk-convert', function (): void {

    it('returns 201 when every order converts cleanly', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Shop',
        ]);

        $orders = collect(range(1, 2))->map(fn ($i) => ECommerceOrder::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'external_order_id' => "EXT-{$i}",
            'order_number' => "ORD-{$i}",
            'status' => 'pending',
            'customer_email' => "c{$i}@example.com",
            'total' => 500,
            'currency' => 'EGP',
            'tax_amount' => 70,
            'shipping_amount' => 0,
            'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 500, 'vat_rate' => 14]],
        ]));

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/ecommerce/bulk-convert', [
                'order_ids' => $orders->pluck('id')->all(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.converted', 2)
            ->assertJsonPath('data.errors', []);
    });

});

// ── Webhook Signature Verification ──

/**
 * Post a raw-body JSON webhook and return the response. json_encode is used
 * so the caller can compute an HMAC over the same bytes the server sees.
 */
function ecommerceWebhookCall($testCase, string $platform, int $channelId, array $payload, array $headers = [])
{
    $raw = json_encode($payload);

    return $testCase->call(
        'POST',
        "/api/v1/webhooks/ecommerce/{$platform}/{$channelId}",
        [],
        [],
        [],
        array_merge(
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all()
        ),
        $raw,
    );
}

describe('POST /api/v1/webhooks/ecommerce/{platform}/{channel}', function (): void {

    it('accepts Shopify webhook with valid base64 HMAC signature', function (): void {
        $secret = 'shopify-secret-xyz';
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Shopify',
            'webhook_secret' => $secret,
        ]);

        $payload = ['topic' => 'orders/create', 'id' => 12345];
        $raw = json_encode($payload);
        $sig = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        ecommerceWebhookCall($this, 'shopify', $channel->id, $payload, ['X-Shopify-Hmac-Sha256' => $sig])
            ->assertOk()
            ->assertJsonPath('handled', true);
    });

    it('accepts WooCommerce webhook with valid base64 HMAC', function (): void {
        $secret = 'wc-secret';
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'woocommerce',
            'name' => 'Woo',
            'webhook_secret' => $secret,
        ]);

        $payload = ['event' => 'order.updated'];
        $raw = json_encode($payload);
        $sig = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        ecommerceWebhookCall($this, 'woocommerce', $channel->id, $payload, ['X-WC-Webhook-Signature' => $sig])
            ->assertOk()
            ->assertJsonPath('handled', true);
    });

    it('accepts Salla webhook with valid hex HMAC', function (): void {
        $secret = 'salla-secret';
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'salla',
            'name' => 'Salla Store',
            'webhook_secret' => $secret,
        ]);

        $payload = ['event' => 'order.created'];
        $raw = json_encode($payload);
        $sig = hash_hmac('sha256', $raw, $secret);

        ecommerceWebhookCall($this, 'salla', $channel->id, $payload, ['X-Salla-Signature' => $sig])
            ->assertOk()
            ->assertJsonPath('handled', true);
    });

    it('accepts Zid webhook with valid hex HMAC', function (): void {
        $secret = 'zid-secret';
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'zid',
            'name' => 'Zid Store',
            'webhook_secret' => $secret,
        ]);

        $payload = ['event' => 'order.cancelled'];
        $raw = json_encode($payload);
        $sig = hash_hmac('sha256', $raw, $secret);

        ecommerceWebhookCall($this, 'zid', $channel->id, $payload, ['X-Zid-Signature' => $sig])
            ->assertOk()
            ->assertJsonPath('handled', true);
    });

    it('rejects webhook with wrong signature', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Shopify',
            'webhook_secret' => 'the-real-secret',
        ]);

        $payload = ['topic' => 'orders/create'];
        $wrongSig = base64_encode(hash_hmac('sha256', json_encode($payload), 'wrong-secret', true));

        ecommerceWebhookCall($this, 'shopify', $channel->id, $payload, ['X-Shopify-Hmac-Sha256' => $wrongSig])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_signature');
    });

    it('rejects webhook with no signature header', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Shopify',
            'webhook_secret' => 'secret',
        ]);

        ecommerceWebhookCall($this, 'shopify', $channel->id, ['topic' => 'orders/create'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_signature');
    });

    it('rejects webhook when channel has no webhook_secret configured', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Shopify',
            'webhook_secret' => null,
        ]);

        ecommerceWebhookCall($this, 'shopify', $channel->id, ['topic' => 'orders/create'], ['X-Shopify-Hmac-Sha256' => 'anything'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'webhook_secret_not_configured');
    });

    it('rejects webhook for nonexistent channel', function (): void {
        ecommerceWebhookCall($this, 'shopify', 99999, ['topic' => 'orders/create'], ['X-Shopify-Hmac-Sha256' => 'anything'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_channel');
    });

    it('rejects webhook when URL platform does not match channel platform', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Shopify',
            'webhook_secret' => 'secret',
        ]);

        ecommerceWebhookCall($this, 'salla', $channel->id, ['event' => 'order.created'], ['X-Salla-Signature' => 'x'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_channel');
    });

    it('rejects webhook for inactive channel', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'shopify',
            'name' => 'Shopify',
            'webhook_secret' => 'secret',
            'is_active' => false,
        ]);

        ecommerceWebhookCall($this, 'shopify', $channel->id, ['topic' => 'orders/create'], ['X-Shopify-Hmac-Sha256' => 'anything'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_channel');
    });

    it('rejects custom platform webhook — use authenticated endpoint instead', function (): void {
        $channel = ECommerceChannel::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'custom',
            'name' => 'Custom',
            'webhook_secret' => 'any',
        ]);

        ecommerceWebhookCall($this, 'custom', $channel->id, ['event' => 'order.created'], ['X-Signature' => 'any'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_signature');
    });

});
