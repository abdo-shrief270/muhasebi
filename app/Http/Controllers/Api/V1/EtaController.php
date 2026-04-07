<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\Invoice;
use App\Domain\EInvoice\Services\EtaComplianceDashboardService;
use App\Domain\EInvoice\Services\EtaDocumentService;
use App\Domain\EInvoice\Services\EtaSettingsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\EInvoice\CancelEtaDocumentRequest;
use App\Http\Requests\EInvoice\UpdateEtaSettingsRequest;
use App\Http\Resources\EtaDocumentResource;
use App\Http\Resources\EtaSettingsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EtaController extends Controller
{
    public function __construct(
        private readonly EtaDocumentService $documentService,
        private readonly EtaSettingsService $settingsService,
        private readonly EtaComplianceDashboardService $dashboardService,
    ) {}

    // ──────────────────────────────────────
    // Settings
    // ──────────────────────────────────────

    public function showSettings(): EtaSettingsResource
    {
        return new EtaSettingsResource(
            $this->settingsService->getSettings(),
        );
    }

    public function updateSettings(UpdateEtaSettingsRequest $request): EtaSettingsResource
    {
        return new EtaSettingsResource(
            $this->settingsService->updateSettings($request->validated()),
        );
    }

    // ──────────────────────────────────────
    // Documents
    // ──────────────────────────────────────

    public function indexDocuments(Request $request): AnonymousResourceCollection
    {
        $documents = $this->documentService->list([
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'search' => $request->query('search'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return EtaDocumentResource::collection($documents);
    }

    public function prepare(Invoice $invoice): EtaDocumentResource
    {
        return new EtaDocumentResource(
            $this->documentService->prepare($invoice),
        );
    }

    public function submit(Invoice $invoice): EtaDocumentResource
    {
        return new EtaDocumentResource(
            $this->documentService->submit($invoice),
        );
    }

    public function showDocument(Invoice $invoice): EtaDocumentResource
    {
        $document = $invoice->etaDocument;

        abort_if(! $document, 404, 'No ETA document found for this invoice.');

        return new EtaDocumentResource(
            $document->load(['invoice.client', 'submission']),
        );
    }

    public function cancelDocument(CancelEtaDocumentRequest $request, Invoice $invoice): EtaDocumentResource
    {
        return new EtaDocumentResource(
            $this->documentService->cancel($invoice, $request->validated('reason')),
        );
    }

    public function checkStatus(Invoice $invoice): EtaDocumentResource
    {
        $document = $invoice->etaDocument;

        abort_if(! $document, 404, 'No ETA document found for this invoice.');

        return new EtaDocumentResource(
            $this->documentService->checkStatus($document),
        );
    }

    // ──────────────────────────────────────
    // Reconciliation
    // ──────────────────────────────────────

    public function reconcile(): JsonResponse
    {
        $result = $this->documentService->reconcile();

        return response()->json([
            'data' => $result,
        ]);
    }

    // ──────────────────────────────────────
    // Compliance Dashboard
    // ──────────────────────────────────────

    public function complianceDashboard(): JsonResponse
    {
        return response()->json([
            'data' => $this->dashboardService->dashboard(),
        ]);
    }

    public function bulkRetry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
        ]);

        $result = $this->dashboardService->bulkRetry($data['document_ids'] ?? []);

        return response()->json([
            'data' => $result,
            'message' => "Prepared: {$result['prepared']}, Submitted: {$result['submitted']}",
        ]);
    }

    public function bulkStatusCheck(): JsonResponse
    {
        $result = $this->dashboardService->bulkStatusCheck();

        return response()->json([
            'data' => $result,
            'message' => "Checked: {$result['checked']}, Updated: {$result['updated']}",
        ]);
    }
}
