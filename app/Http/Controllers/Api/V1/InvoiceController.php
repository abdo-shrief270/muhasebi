<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\InvoicePdfService;
use App\Domain\Billing\Services\InvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = $this->invoiceService->list([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'type' => $request->query('type'),
            'client_id' => $request->query('client_id'),
            'date_from' => $request->query('from'),
            'date_to' => $request->query('to'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return InvoiceResource::collection($invoices);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoiceService->create($request->validated());

        return (new InvoiceResource($invoice->load(['lines', 'client'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource(
            $invoice->load(['client', 'lines', 'payments', 'creditNotes', 'createdByUser'])
        );
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): InvoiceResource
    {
        $invoice = $this->invoiceService->update($invoice, $request->validated());

        return new InvoiceResource($invoice->load(['lines', 'client']));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->invoiceService->delete($invoice);

        return response()->json([
            'message' => 'Invoice deleted successfully.',
        ]);
    }

    public function send(Invoice $invoice): InvoiceResource
    {
        $invoice = $this->invoiceService->send($invoice);

        return new InvoiceResource($invoice);
    }

    public function cancel(Invoice $invoice): InvoiceResource
    {
        $invoice = $this->invoiceService->cancel($invoice);

        return new InvoiceResource($invoice);
    }

    public function postToGL(Invoice $invoice): InvoiceResource
    {
        $invoice = $this->invoiceService->postToGL($invoice);

        return new InvoiceResource($invoice->load('journalEntry'));
    }

    public function creditNote(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.description' => ['required_with:lines', 'string', 'max:500'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        $creditNote = $this->invoiceService->createCreditNote($invoice, $validated);

        return (new InvoiceResource($creditNote->load(['lines', 'client'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function pdf(Invoice $invoice): Response
    {
        return app(InvoicePdfService::class)->download($invoice);
    }
}
