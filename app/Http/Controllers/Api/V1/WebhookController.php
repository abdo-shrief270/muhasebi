<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\ClientPortal\Services\ClientPaymentService;
use App\Domain\Subscription\Services\FawryService;
use App\Domain\Subscription\Services\PaymobService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymobService $paymobService,
        private readonly ClientPaymentService $clientPaymentService,
    ) {}

    /**
     * Handle Paymob webhook callback.
     */
    public function paymob(Request $request): JsonResponse
    {
        try {
            $result = $this->paymobService->handleCallback($request->all());

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('Paymob webhook failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle Fawry webhook callback.
     * Routes to invoice or subscription handler based on merchant ref format.
     */
    public function fawry(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $merchantRef = $data['merchantRefNum'] ?? '';

            // Invoice payments use "INV-FAWRY-{id}-{ts}" format
            if (str_starts_with($merchantRef, 'INV-FAWRY-')) {
                $result = $this->clientPaymentService->handleFawryCallback($data);

                return response()->json($result);
            }

            // Default: subscription payment
            $result = FawryService::handleCallback($data);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('Fawry webhook failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }
}
