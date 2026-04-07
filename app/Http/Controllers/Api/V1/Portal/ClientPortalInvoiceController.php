<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\Billing\Models\Invoice;
use App\Domain\ClientPortal\Services\ClientPaymentService;
use App\Domain\ClientPortal\Services\ClientPortalService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PortalInvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ClientPortalInvoiceController extends Controller
{
    public function __construct(
        private readonly ClientPortalService $portalService,
        private readonly ClientPaymentService $paymentService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PortalInvoiceResource::collection(
            $this->portalService->listInvoices(app('portal.client'), [
                'status' => $request->query('status'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'search' => $request->query('search'),
                'per_page' => min((int) ($request->query('per_page', 15)), 100),
            ]),
        );
    }

    public function show(Invoice $invoice): PortalInvoiceResource
    {
        return new PortalInvoiceResource(
            $this->portalService->showInvoice(app('portal.client'), $invoice),
        );
    }

    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'gateway' => ['required', 'string', 'in:paymob,fawry'],
        ]);

        $gateway = $request->input('gateway', 'paymob');
        $result = $this->paymentService->initiatePayment($invoice, auth()->user(), $gateway);

        return response()->json(['data' => $result]);
    }

    public function gateways(): JsonResponse
    {
        return response()->json([
            'data' => $this->paymentService->availableGateways(),
        ]);
    }

    public function pdf(Invoice $invoice): Response
    {
        // Verify the invoice belongs to the portal user's client
        $user = auth()->user();
        if ($invoice->client_id !== $user->client_id) {
            abort(403);
        }

        return app(\App\Domain\Billing\Services\InvoicePdfService::class)->download($invoice);
    }
}
