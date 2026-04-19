<?php

declare(strict_types=1);

namespace App\Domain\ECommerce\Services;

use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Client\Models\Client;
use App\Domain\ECommerce\Models\ECommerceChannel;
use App\Domain\ECommerce\Models\ECommerceOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ECommerceService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    // ── Channel CRUD ──

    /**
     * List channels for the current tenant.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listChannels(array $filters = []): LengthAwarePaginator
    {
        return ECommerceChannel::query()
            ->withCount('orders')
            ->when(isset($filters['platform']), fn ($q) => $q->where('platform', $filters['platform']))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new e-commerce channel.
     *
     * @param  array<string, mixed>  $data
     */
    public function createChannel(array $data): ECommerceChannel
    {
        $data['created_by'] = Auth::id();

        return ECommerceChannel::create($data);
    }

    /**
     * Update an existing channel.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateChannel(ECommerceChannel $channel, array $data): ECommerceChannel
    {
        $channel->update($data);

        return $channel->refresh();
    }

    /**
     * Soft-delete a channel.
     */
    public function deleteChannel(ECommerceChannel $channel): void
    {
        $channel->delete();
    }

    // ── Order Sync ──

    /**
     * Sync orders from an e-commerce channel.
     * Placeholder — actual API integration to be implemented per platform.
     *
     * @return array{synced: int, message: string}
     */
    public function syncOrders(ECommerceChannel $channel): array
    {
        // TODO: Implement per-platform API calls (Shopify, WooCommerce, Salla, Zid)
        $channel->update([
            'last_sync_at' => now(),
            'sync_status' => 'idle',
        ]);

        return [
            'synced' => 0,
            'message' => "Sync placeholder for {$channel->platform->label()}. API integration not yet implemented.",
        ];
    }

    // ── Order → Invoice Conversion ──

    /**
     * Convert an e-commerce order to an invoice.
     * Finds or creates the client, maps order items to invoice lines, and uses bcmath for precision.
     */
    public function convertToInvoice(ECommerceOrder $order): ECommerceOrder
    {
        if ($order->synced_invoice_id) {
            return $order->load('syncedInvoice');
        }

        $client = $this->findOrCreateClient($order);

        $lines = $this->mapOrderItemsToLines($order);

        $invoice = $this->invoiceService->create([
            'client_id' => $client->id,
            'date' => $order->created_at->toDateString(),
            'currency' => $order->currency,
            'notes' => "Imported from e-commerce order #{$order->order_number}",
            'lines' => $lines,
        ]);

        $order->update([
            'synced_invoice_id' => $invoice->id,
            'synced_at' => now(),
            'status' => 'synced',
        ]);

        return $order->refresh()->load('syncedInvoice');
    }

    /**
     * Bulk convert multiple orders to invoices.
     *
     * @param  array<int>  $orderIds
     * @return array{converted: int, errors: array<int, string>}
     */
    public function bulkConvert(array $orderIds): array
    {
        $converted = 0;
        $errors = [];

        foreach ($orderIds as $orderId) {
            try {
                $order = ECommerceOrder::findOrFail($orderId);
                $this->convertToInvoice($order);
                $converted++;
            } catch (\Throwable $e) {
                $errors[$orderId] = $e->getMessage();
                Log::warning("E-commerce bulk convert failed for order {$orderId}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'converted' => $converted,
            'errors' => $errors,
        ];
    }

    // ── Webhook ──

    /**
     * Handle incoming webhook from an e-commerce platform. Signature is
     * verified upstream by VerifyEcommerceWebhookSignature — this method
     * only dispatches based on event type.
     *
     * @param  array<string, mixed>  $payload
     * @return array{handled: bool, event: string|null}
     */
    public function webhookHandler(string $platform, array $payload, ?ECommerceChannel $channel = null): array
    {
        $eventType = $payload['event'] ?? $payload['topic'] ?? $payload['type'] ?? null;

        Log::info('E-commerce webhook received', [
            'platform' => $platform,
            'channel_id' => $channel?->id,
            'event' => $eventType,
        ]);

        return match ($eventType) {
            'order.created', 'orders/create' => $this->handleOrderCreated($platform, $payload),
            'order.updated', 'orders/updated' => $this->handleOrderUpdated($platform, $payload),
            'order.cancelled', 'orders/cancelled' => $this->handleOrderCancelled($platform, $payload),
            default => ['handled' => false, 'event' => $eventType],
        };
    }

    // ── Dashboard ──

    /**
     * Dashboard stats per channel.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $channels = ECommerceChannel::query()
            ->withCount('orders')
            ->withSum('orders', 'total')
            ->get();

        $totalOrders = $channels->sum('orders_count');
        $totalRevenue = $channels->sum('orders_sum_total');
        $pendingOrders = ECommerceOrder::where('status', 'pending')->count();
        $syncedOrders = ECommerceOrder::where('status', 'synced')->count();

        return [
            'channels' => $channels,
            'summary' => [
                'total_channels' => $channels->count(),
                'active_channels' => $channels->where('is_active', true)->count(),
                'total_orders' => $totalOrders,
                'pending_orders' => $pendingOrders,
                'synced_orders' => $syncedOrders,
                'total_revenue' => number_format((float) $totalRevenue, 2, '.', ''),
                'currency' => 'EGP',
            ],
        ];
    }

    // ── Private Helpers ──

    /**
     * Find an existing client by email or create a new one from order data.
     */
    private function findOrCreateClient(ECommerceOrder $order): Client
    {
        if ($order->customer_email) {
            $client = Client::where('email', $order->customer_email)->first();
            if ($client) {
                return $client;
            }
        }

        return Client::create([
            'name' => $order->customer_name ?? 'E-Commerce Customer',
            'email' => $order->customer_email,
            'is_active' => true,
        ]);
    }

    /**
     * Map order items JSON to invoice line arrays.
     * Uses bcmath for monetary precision.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapOrderItemsToLines(ECommerceOrder $order): array
    {
        $items = $order->items ?? [];
        $lines = [];

        foreach ($items as $index => $item) {
            $quantity = (string) ($item['quantity'] ?? 1);
            $unitPrice = (string) ($item['unit_price'] ?? $item['price'] ?? '0');

            // Use bcmath for precision
            $lineTotal = bcmul($quantity, $unitPrice, 2);

            $lines[] = [
                'description' => $item['name'] ?? $item['title'] ?? "Item #{$index}",
                'quantity' => (int) $quantity,
                'unit_price' => $unitPrice,
                'vat_rate' => $item['vat_rate'] ?? 14,
                'sort_order' => $index,
            ];
        }

        // Add shipping as a line if present
        if (bccomp($order->shipping_amount ?? '0', '0', 2) > 0) {
            $lines[] = [
                'description' => 'Shipping',
                'quantity' => 1,
                'unit_price' => $order->shipping_amount,
                'vat_rate' => 0,
                'sort_order' => count($lines),
            ];
        }

        return $lines;
    }

    /**
     * Handle order.created webhook event.
     *
     * @param  array<string, mixed>  $payload
     * @return array{handled: bool, event: string}
     */
    private function handleOrderCreated(string $platform, array $payload): array
    {
        // TODO: Parse payload per platform, create ECommerceOrder
        Log::info('E-commerce order.created webhook placeholder', compact('platform'));

        return ['handled' => true, 'event' => 'order.created'];
    }

    /**
     * Handle order.updated webhook event.
     *
     * @param  array<string, mixed>  $payload
     * @return array{handled: bool, event: string}
     */
    private function handleOrderUpdated(string $platform, array $payload): array
    {
        Log::info('E-commerce order.updated webhook placeholder', compact('platform'));

        return ['handled' => true, 'event' => 'order.updated'];
    }

    /**
     * Handle order.cancelled webhook event.
     *
     * @param  array<string, mixed>  $payload
     * @return array{handled: bool, event: string}
     */
    private function handleOrderCancelled(string $platform, array $payload): array
    {
        Log::info('E-commerce order.cancelled webhook placeholder', compact('platform'));

        return ['handled' => true, 'event' => 'order.cancelled'];
    }
}
