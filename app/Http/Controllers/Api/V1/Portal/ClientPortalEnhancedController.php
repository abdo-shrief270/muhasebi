<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\ClientPortal\Models\InvoiceDispute;
use App\Domain\ClientPortal\Models\PaymentPlanInstallment;
use App\Domain\ClientPortal\Services\ClientPortalEnhancedService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientPortalEnhancedController extends Controller
{
    public function __construct(
        private readonly ClientPortalEnhancedService $enhancedService,
    ) {}

    // ──────────────────────────────────────
    // Disputes
    // ──────────────────────────────────────

    public function disputes(Request $request): JsonResponse
    {
        $client = app('portal.client');

        return response()->json([
            'data' => $this->enhancedService->listDisputes($client->id),
        ]);
    }

    public function createDispute(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high'],
        ]);

        $client = app('portal.client');
        $dispute = $this->enhancedService->createDispute($client->id, $request->only([
            'invoice_id', 'subject', 'description', 'priority',
        ]));

        return response()->json(['data' => $dispute], 201);
    }

    public function disputeShow(InvoiceDispute $dispute): JsonResponse
    {
        $client = app('portal.client');
        abort_unless($dispute->client_id === $client->id, 403, 'This dispute does not belong to your account.');

        return response()->json([
            'data' => $dispute->load(['invoice', 'resolver']),
        ]);
    }

    // ──────────────────────────────────────
    // Payment Plans
    // ──────────────────────────────────────

    public function paymentPlans(): JsonResponse
    {
        $client = app('portal.client');

        return response()->json([
            'data' => $this->enhancedService->listPaymentPlans($client->id),
        ]);
    }

    public function createPaymentPlan(Request $request, int $invoice): JsonResponse
    {
        $request->validate([
            'installments' => ['required', 'integer', 'min:2', 'max:60'],
            'frequency' => ['required', 'string', 'in:weekly,biweekly,monthly'],
        ]);

        $plan = $this->enhancedService->createPaymentPlan(
            invoiceId: $invoice,
            installments: (int) $request->input('installments'),
            frequency: $request->input('frequency'),
        );

        return response()->json(['data' => $plan], 201);
    }

    public function payInstallment(Request $request, PaymentPlanInstallment $installment): JsonResponse
    {
        $client = app('portal.client');
        $plan = $installment->plan;
        abort_unless($plan->client_id === $client->id, 403, 'This installment does not belong to your account.');

        $result = $this->enhancedService->recordInstallmentPayment($installment, $request->all());

        return response()->json(['data' => $result]);
    }

    // ──────────────────────────────────────
    // Reports
    // ──────────────────────────────────────

    public function clientReport(): JsonResponse
    {
        $client = app('portal.client');

        return response()->json([
            'data' => $this->enhancedService->clientReports($client->id),
        ]);
    }
}
