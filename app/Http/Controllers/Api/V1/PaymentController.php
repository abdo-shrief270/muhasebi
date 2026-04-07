<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Services\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $payments = $this->paymentService->list([
            'invoice_id' => $request->query('invoice_id'),
            'client_id' => $request->query('client_id'),
            'method' => $request->query('method'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'sort_by' => $request->query('sort_by', 'date'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return PaymentResource::collection($payments);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $invoice = Invoice::query()->findOrFail($validated['invoice_id']);

        $payment = $this->paymentService->record($invoice, $validated);

        return (new PaymentResource($payment->load('invoice')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $this->paymentService->delete($payment);

        return response()->json([
            'message' => 'Payment deleted successfully.',
        ]);
    }
}
