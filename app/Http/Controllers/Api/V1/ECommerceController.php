<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\ECommerce\Models\ECommerceChannel;
use App\Domain\ECommerce\Models\ECommerceOrder;
use App\Domain\ECommerce\Services\ECommerceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ECommerceController extends Controller
{
    public function __construct(
        private readonly ECommerceService $service,
    ) {}

    // ── Channel CRUD ──

    public function index(Request $request): JsonResponse
    {
        $data = $this->service->listChannels([
            'platform' => $request->query('platform'),
            'is_active' => $request->query('is_active'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'string', 'in:shopify,woocommerce,salla,zid,custom'],
            'name' => ['required', 'string', 'max:255'],
            'api_url' => ['nullable', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:1000'],
            'api_secret' => ['nullable', 'string', 'max:1000'],
            'webhook_secret' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        $channel = $this->service->createChannel($data);

        return response()->json([
            'data' => $channel,
            'message' => 'E-commerce channel created.',
        ], Response::HTTP_CREATED);
    }

    public function show(ECommerceChannel $ecommerceChannel): JsonResponse
    {
        return response()->json([
            'data' => $ecommerceChannel->loadCount('orders'),
        ]);
    }

    public function update(Request $request, ECommerceChannel $ecommerceChannel): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['sometimes', 'string', 'in:shopify,woocommerce,salla,zid,custom'],
            'name' => ['sometimes', 'string', 'max:255'],
            'api_url' => ['nullable', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:1000'],
            'api_secret' => ['nullable', 'string', 'max:1000'],
            'webhook_secret' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        $channel = $this->service->updateChannel($ecommerceChannel, $data);

        return response()->json([
            'data' => $channel,
            'message' => 'E-commerce channel updated.',
        ]);
    }

    public function destroy(ECommerceChannel $ecommerceChannel): JsonResponse
    {
        $this->service->deleteChannel($ecommerceChannel);

        return response()->json(['message' => 'E-commerce channel deleted.']);
    }

    // ── Sync & Convert ──

    public function syncOrders(ECommerceChannel $ecommerceChannel): JsonResponse
    {
        $result = $this->service->syncOrders($ecommerceChannel);

        return response()->json([
            'data' => $result,
            'message' => $result['message'],
        ]);
    }

    public function convertToInvoice(ECommerceOrder $ecommerceOrder): JsonResponse
    {
        $order = $this->service->convertToInvoice($ecommerceOrder);

        return response()->json([
            'data' => $order,
            'message' => 'Order converted to invoice.',
        ]);
    }

    public function bulkConvert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'integer', 'exists:ecommerce_orders,id'],
        ]);

        $result = $this->service->bulkConvert($data['order_ids']);

        return response()->json([
            'data' => $result,
            'message' => "Converted {$result['converted']} orders.",
        ]);
    }

    // ── Dashboard ──

    public function dashboard(): JsonResponse
    {
        return response()->json($this->service->dashboard());
    }

    // ── Webhook (public, signature-verified by VerifyEcommerceWebhookSignature) ──

    public function webhook(Request $request, string $platform, int $channel): JsonResponse
    {
        $verifiedChannel = $request->attributes->get('ecommerce_channel');
        $result = $this->service->webhookHandler($platform, $request->all(), $verifiedChannel);

        return response()->json($result);
    }
}
