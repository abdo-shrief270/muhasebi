<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Client\Models\Client;
use App\Domain\EInvoice\Enums\EtaDocumentStatus;
use App\Domain\EInvoice\Enums\EtaDocumentType;
use App\Domain\EInvoice\Models\EtaDocument;
use App\Domain\EInvoice\Models\EtaItemCode;
use App\Domain\EInvoice\Models\EtaSettings;
use App\Domain\EInvoice\Models\EtaSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EtaDocumentService
{
    public function __construct(
        private readonly EtaApiService $apiService,
        private readonly EtaSettingsService $settingsService,
    ) {}

    /**
     * Prepare an invoice for ETA submission (transform to ETA JSON).
     *
     * @throws ValidationException
     */
    public function prepare(Invoice $invoice): EtaDocument
    {
        $settings = $this->settingsService->ensureEnabled();

        $this->validateInvoiceForEta($invoice);

        // Check if an ETA document already exists for this invoice
        $existing = EtaDocument::query()->forInvoice($invoice->id)->first();

        if ($existing && ! $existing->status->isTerminal()) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'An ETA document already exists for this invoice.',
                    'يوجد مستند إلكتروني بالفعل لهذه الفاتورة.',
                ],
            ]);
        }

        // If previous was rejected, delete it so we can re-prepare
        if ($existing && $existing->status === EtaDocumentStatus::Rejected) {
            $existing->delete();
        }

        $invoice->load(['client', 'lines']);

        $documentJson = $this->buildDocumentJson($invoice, $settings);
        $signedData = $this->serializeForSigning($documentJson);

        return EtaDocument::query()->create([
            'tenant_id' => (int) app('tenant.id'),
            'invoice_id' => $invoice->id,
            'document_type' => EtaDocumentType::fromInvoiceType($invoice->type),
            'internal_id' => $invoice->invoice_number,
            'status' => EtaDocumentStatus::Prepared,
            'document_data' => $documentJson,
            'signed_data' => $signedData,
        ]);
    }

    /**
     * Submit a prepared ETA document to the ETA API.
     *
     * @throws ValidationException
     */
    public function submit(Invoice $invoice): EtaDocument
    {
        $document = EtaDocument::query()->forInvoice($invoice->id)->first();

        if (! $document) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'No prepared ETA document found for this invoice. Please prepare first.',
                    'لا يوجد مستند إلكتروني جاهز لهذه الفاتورة. يرجى التجهيز أولاً.',
                ],
            ]);
        }

        if (! $document->status->canSubmit()) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot submit document with status: {$document->status->value}.",
                    "لا يمكن إرسال مستند بحالة: {$document->status->labelAr()}.",
                ],
            ]);
        }

        return DB::transaction(function () use ($document): EtaDocument {
            // Create a submission record
            $submission = EtaSubmission::query()->create([
                'tenant_id' => (int) app('tenant.id'),
                'status' => 'pending',
                'document_count' => 1,
                'submitted_at' => now(),
                'submitted_by' => Auth::id(),
            ]);

            $document->update([
                'eta_submission_id' => $submission->id,
            ]);

            try {
                $response = $this->apiService->submitDocuments([$document->document_data]);

                // Parse ETA response
                $submissionUuid = $response['submissionId'] ?? null;
                $acceptedDocuments = $response['acceptedDocuments'] ?? [];
                $rejectedDocuments = $response['rejectedDocuments'] ?? [];

                $submission->update([
                    'submission_uuid' => $submissionUuid,
                    'status' => 'completed',
                    'accepted_count' => count($acceptedDocuments),
                    'rejected_count' => count($rejectedDocuments),
                    'response_data' => $response,
                ]);

                if (count($acceptedDocuments) > 0) {
                    $accepted = $acceptedDocuments[0];

                    $document->update([
                        'eta_uuid' => $accepted['uuid'] ?? null,
                        'eta_long_id' => $accepted['longId'] ?? null,
                        'status' => EtaDocumentStatus::Submitted,
                        'eta_response' => $accepted,
                        'submitted_at' => now(),
                    ]);
                } elseif (count($rejectedDocuments) > 0) {
                    $rejected = $rejectedDocuments[0];

                    $document->update([
                        'status' => EtaDocumentStatus::Invalid,
                        'eta_response' => $rejected,
                        'errors' => $rejected['error'] ?? null,
                        'submitted_at' => now(),
                    ]);
                }

                return $document->refresh();
            } catch (\Throwable $e) {
                $submission->update([
                    'status' => 'failed',
                    'response_data' => ['error' => $e->getMessage()],
                ]);

                throw $e;
            }
        });
    }

    /**
     * Check the status of an ETA document by polling the ETA API.
     *
     * @throws ValidationException
     */
    public function checkStatus(EtaDocument $document): EtaDocument
    {
        if (! $document->eta_uuid) {
            throw ValidationException::withMessages([
                'eta' => [
                    'Document has no ETA UUID. It may not have been submitted yet.',
                    'المستند ليس له UUID من مصلحة الضرائب. ربما لم يتم إرساله بعد.',
                ],
            ]);
        }

        $response = $this->apiService->getDocumentStatus($document->eta_uuid);

        $etaStatus = $response['status'] ?? null;
        $newStatus = $this->mapEtaStatus($etaStatus);

        $updateData = [
            'eta_response' => $response,
        ];

        if ($newStatus !== null && $newStatus !== $document->status) {
            $updateData['status'] = $newStatus;
        }

        // Extract QR code if document is valid
        if ($newStatus === EtaDocumentStatus::Valid) {
            $updateData['qr_code_data'] = $this->buildQrCodeData($document, $response);
        }

        // Extract errors if invalid/rejected
        if (in_array($newStatus, [EtaDocumentStatus::Invalid, EtaDocumentStatus::Rejected], true)) {
            $updateData['errors'] = $response['validationResults']['validationSteps'] ?? $response['error'] ?? null;
        }

        $document->update($updateData);

        return $document->refresh();
    }

    /**
     * Cancel a document at ETA.
     *
     * @throws ValidationException
     */
    public function cancel(Invoice $invoice, string $reason): EtaDocument
    {
        $document = EtaDocument::query()->forInvoice($invoice->id)->first();

        if (! $document) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'No ETA document found for this invoice.',
                    'لا يوجد مستند إلكتروني لهذه الفاتورة.',
                ],
            ]);
        }

        if (! $document->status->canCancel()) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot cancel document with status: {$document->status->value}. Only valid documents can be cancelled.",
                    "لا يمكن إلغاء مستند بحالة: {$document->status->labelAr()}. يمكن إلغاء المستندات الصالحة فقط.",
                ],
            ]);
        }

        $this->apiService->cancelDocument($document->eta_uuid, $reason);

        $document->update([
            'status' => EtaDocumentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => Auth::id(),
        ]);

        return $document->refresh();
    }

    /**
     * List ETA documents with filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return EtaDocument::query()
            ->with(['invoice.client'])
            ->when(
                isset($filters['status']),
                fn ($q) => $q->ofStatus(EtaDocumentStatus::from($filters['status']))
            )
            ->when(
                isset($filters['from']) && isset($filters['to']),
                fn ($q) => $q->whereBetween('created_at', [$filters['from'], $filters['to']])
            )
            ->when(
                isset($filters['search']),
                fn ($q) => $q->where(function ($q) use ($filters): void {
                    $q->where('internal_id', 'ilike', "%{$filters['search']}%")
                        ->orWhere('eta_uuid', 'ilike', "%{$filters['search']}%");
                })
            )
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Reconcile local ETA documents against the ETA API.
     *
     * @return array{matched: int, mismatched: int, details: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function reconcile(): array
    {
        $response = $this->apiService->getRecentDocuments();

        $etaDocuments = $response['result'] ?? [];
        $matched = 0;
        $mismatched = 0;
        $details = [];

        foreach ($etaDocuments as $etaDoc) {
            $uuid = $etaDoc['uuid'] ?? null;

            if (! $uuid) {
                continue;
            }

            $localDoc = EtaDocument::query()->where('eta_uuid', $uuid)->first();

            if (! $localDoc) {
                $details[] = [
                    'eta_uuid' => $uuid,
                    'issue' => 'exists_in_eta_only',
                    'eta_status' => $etaDoc['status'] ?? 'unknown',
                ];
                $mismatched++;

                continue;
            }

            $etaStatus = $this->mapEtaStatus($etaDoc['status'] ?? null);

            if ($etaStatus !== null && $etaStatus !== $localDoc->status) {
                $details[] = [
                    'eta_uuid' => $uuid,
                    'internal_id' => $localDoc->internal_id,
                    'issue' => 'status_mismatch',
                    'local_status' => $localDoc->status->value,
                    'eta_status' => $etaStatus->value,
                ];

                // Auto-update local status
                $localDoc->update(['status' => $etaStatus]);

                $mismatched++;
            } else {
                $matched++;
            }
        }

        return [
            'matched' => $matched,
            'mismatched' => $mismatched,
            'details' => $details,
        ];
    }

    // ──────────────────────────────────────
    // Private: Validation
    // ──────────────────────────────────────

    /**
     * @throws ValidationException
     */
    private function validateInvoiceForEta(Invoice $invoice): void
    {
        $submittableStatuses = [
            InvoiceStatus::Sent,
            InvoiceStatus::PartiallyPaid,
            InvoiceStatus::Paid,
            InvoiceStatus::Overdue,
        ];

        if (! in_array($invoice->status, $submittableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Invoice must be sent or paid before submitting to ETA. Current status: {$invoice->status->value}.",
                    "يجب إرسال الفاتورة أو دفعها قبل تقديمها للضرائب. الحالة الحالية: {$invoice->status->labelAr()}.",
                ],
            ]);
        }

        $invoice->load('client');

        if (! $invoice->client) {
            throw ValidationException::withMessages([
                'client' => [
                    'Invoice must have a client assigned.',
                    'يجب أن يكون للفاتورة عميل محدد.',
                ],
            ]);
        }

        if (! $invoice->client->tax_id) {
            throw ValidationException::withMessages([
                'client' => [
                    'Client must have a Tax ID (TIN) for ETA submission.',
                    'يجب أن يكون للعميل رقم تسجيل ضريبي للتقديم لمصلحة الضرائب.',
                ],
            ]);
        }
    }

    // ──────────────────────────────────────
    // Private: JSON Building
    // ──────────────────────────────────────

    /**
     * Build the full ETA document JSON from an invoice.
     *
     * @return array<string, mixed>
     */
    private function buildDocumentJson(Invoice $invoice, EtaSettings $settings): array
    {
        $issuer = $this->buildIssuerJson($settings);
        $receiver = $this->buildReceiverJson($invoice->client);
        $lines = [];
        $totalSalesAmount = '0';
        $totalDiscountAmount = '0';
        $netAmount = '0';
        $totalVatAmount = '0';
        $totalAmount = '0';

        foreach ($invoice->lines as $index => $line) {
            $lineJson = $this->buildLineJson($line, $index + 1);
            $lines[] = $lineJson;

            $totalSalesAmount = bcadd($totalSalesAmount, (string) $lineJson['salesTotal'], 5);
            $totalDiscountAmount = bcadd($totalDiscountAmount, (string) ($lineJson['discount']['amount'] ?? '0'), 5);
            $netAmount = bcadd($netAmount, (string) $lineJson['netTotal'], 5);

            foreach ($lineJson['taxableItems'] as $tax) {
                $totalVatAmount = bcadd($totalVatAmount, (string) $tax['amount'], 5);
            }

            $totalAmount = bcadd($totalAmount, (string) $lineJson['total'], 5);
        }

        $extraDiscountAmount = (string) ($invoice->discount_amount ?? '0');

        return [
            'issuer' => $issuer,
            'receiver' => $receiver,
            'documentType' => EtaDocumentType::fromInvoiceType($invoice->type)->value,
            'documentTypeVersion' => '1.0',
            'dateTimeIssued' => $invoice->date->toIso8601String(),
            'taxpayerActivityCode' => $settings->activity_code ?? '0000',
            'internalID' => $invoice->invoice_number,
            'invoiceLines' => $lines,
            'totalSalesAmount' => round((float) $totalSalesAmount, 5),
            'totalDiscountAmount' => round((float) $totalDiscountAmount, 5),
            'netAmount' => round((float) $netAmount, 5),
            'taxTotals' => [
                [
                    'taxType' => 'T1',
                    'amount' => round((float) $totalVatAmount, 5),
                ],
            ],
            'totalAmount' => round((float) $totalAmount, 5),
            'extraDiscountAmount' => round((float) $extraDiscountAmount, 5),
            'totalItemsDiscountAmount' => 0,
        ];
    }

    /**
     * Build the issuer (seller) JSON block.
     *
     * @return array<string, mixed>
     */
    private function buildIssuerJson(EtaSettings $settings): array
    {
        $tenant = \App\Domain\Tenant\Models\Tenant::query()->find((int) app('tenant.id'));

        return [
            'type' => 'B',
            'id' => $tenant?->tax_id ?? '',
            'name' => $settings->company_trade_name ?? $tenant?->name ?? '',
            'address' => [
                'branchID' => $settings->branch_id ?? '0',
                'country' => $settings->branch_address_country ?? 'EG',
                'governate' => $settings->branch_address_governate ?? '',
                'regionCity' => $settings->branch_address_region_city ?? '',
                'street' => $settings->branch_address_street ?? '',
                'buildingNumber' => $settings->branch_address_building_number ?? '',
            ],
        ];
    }

    /**
     * Build the receiver (buyer) JSON block.
     *
     * @return array<string, mixed>
     */
    private function buildReceiverJson(Client $client): array
    {
        return [
            'type' => 'B',
            'id' => $client->tax_id ?? '',
            'name' => $client->name,
            'address' => [
                'country' => 'EG',
                'governate' => $client->city ?? '',
                'regionCity' => $client->city ?? '',
                'street' => $client->address ?? '',
                'buildingNumber' => '',
            ],
        ];
    }

    /**
     * Build a single invoice line JSON for ETA.
     *
     * @return array<string, mixed>
     */
    private function buildLineJson(InvoiceLine $line, int $index): array
    {
        // Try to find a matching ETA item code
        $itemCode = EtaItemCode::query()
            ->active()
            ->where(function ($q) use ($line): void {
                $q->where('description', $line->description)
                    ->orWhere('description_ar', $line->description);
            })
            ->first();

        $codeType = $itemCode?->code_type ?? 'EGS';
        $codeValue = $itemCode?->item_code ?? 'EG-0000-0000';
        $unitType = $itemCode?->unit_type ?? 'EA';
        $taxType = $itemCode?->default_tax_type ?? 'T1';
        $taxSubtype = $itemCode?->default_tax_subtype ?? 'V009';

        $quantity = (string) $line->quantity;
        $unitPrice = (string) $line->unit_price;
        $salesTotal = bcmul($quantity, $unitPrice, 5);

        $discountRate = (string) ($line->discount_percent ?? '0');
        $discountAmount = bcdiv(bcmul($salesTotal, $discountRate, 5), '100', 5);

        $netTotal = bcsub($salesTotal, $discountAmount, 5);

        $vatRate = (string) ($line->vat_rate ?? '14');
        $vatAmount = bcdiv(bcmul($netTotal, $vatRate, 5), '100', 5);

        $total = bcadd($netTotal, $vatAmount, 5);

        // Map VAT rate to ETA tax subtype
        if (bccomp($vatRate, '14', 2) === 0) {
            $taxSubtype = 'V009'; // Standard 14%
        } elseif (bccomp($vatRate, '0', 2) === 0) {
            $taxSubtype = 'V001'; // Exempt
        }

        return [
            'description' => $line->description,
            'itemType' => $codeType,
            'itemCode' => $codeValue,
            'unitType' => $unitType,
            'quantity' => round((float) $quantity, 5),
            'internalCode' => (string) $line->id,
            'unitValue' => [
                'currencySold' => 'EGP',
                'amountEGP' => round((float) $unitPrice, 5),
            ],
            'salesTotal' => round((float) $salesTotal, 5),
            'discount' => [
                'rate' => round((float) $discountRate, 5),
                'amount' => round((float) $discountAmount, 5),
            ],
            'netTotal' => round((float) $netTotal, 5),
            'taxableItems' => [
                [
                    'taxType' => $taxType,
                    'subType' => $taxSubtype,
                    'amount' => round((float) $vatAmount, 5),
                    'rate' => round((float) $vatRate, 5),
                ],
            ],
            'total' => round((float) $total, 5),
            'valueDifference' => 0,
            'itemsDiscount' => 0,
        ];
    }

    // ──────────────────────────────────────
    // Private: Serialization & Helpers
    // ──────────────────────────────────────

    /**
     * Serialize a document JSON into canonical form for digital signing.
     * Keys are sorted alphabetically, values formatted per ETA spec.
     */
    private function serializeForSigning(array $data): string
    {
        return $this->canonicalize($data);
    }

    /**
     * Recursively canonicalize an array: sort keys, format values.
     */
    private function canonicalize(mixed $data): string
    {
        if (is_array($data)) {
            // Check if sequential array (list)
            if (array_is_list($data)) {
                $parts = array_map(fn ($item) => $this->canonicalize($item), $data);

                return '"' . implode('"', $parts) . '"';
            }

            // Associative array: sort by key
            ksort($data);

            $parts = [];

            foreach ($data as $key => $value) {
                $parts[] = '"' . strtoupper((string) $key) . '"';
                $parts[] = $this->canonicalize($value);
            }

            return implode('', $parts);
        }

        if (is_float($data) || is_int($data)) {
            return '"' . number_format((float) $data, 5, '.', '') . '"';
        }

        if (is_bool($data)) {
            return '"' . ($data ? 'true' : 'false') . '"';
        }

        if (is_null($data)) {
            return '""';
        }

        return '"' . (string) $data . '"';
    }

    /**
     * Map an ETA status string to our EtaDocumentStatus enum.
     */
    private function mapEtaStatus(?string $etaStatus): ?EtaDocumentStatus
    {
        if (! $etaStatus) {
            return null;
        }

        return match (strtolower($etaStatus)) {
            'valid' => EtaDocumentStatus::Valid,
            'invalid' => EtaDocumentStatus::Invalid,
            'rejected' => EtaDocumentStatus::Rejected,
            'cancelled' => EtaDocumentStatus::Cancelled,
            'submitted' => EtaDocumentStatus::Submitted,
            default => null,
        };
    }

    /**
     * Build QR code data string from ETA response.
     */
    private function buildQrCodeData(EtaDocument $document, array $response): string
    {
        $uuid = $document->eta_uuid ?? '';
        $longId = $document->eta_long_id ?? $response['longId'] ?? '';

        // ETA QR format: URL with uuid and longId
        $baseUrl = $this->settingsService->getSettings()->environment === 'production'
            ? 'https://invoicing.eta.gov.eg'
            : 'https://preprod.invoicing.eta.gov.eg';

        return "{$baseUrl}/documents/{$uuid}/share/{$longId}";
    }
}
