<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Services;

use App\Domain\EInvoice\Enums\EtaAmendmentStatus;
use App\Domain\EInvoice\Enums\EtaAmendmentType;
use App\Domain\EInvoice\Enums\EtaDocumentStatus;
use App\Domain\EInvoice\Models\EtaAmendment;
use App\Domain\EInvoice\Models\EtaDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EtaAmendmentService
{
    public function __construct(
        private readonly EtaApiService $apiService,
    ) {}

    // ──────────────────────────────────────
    // Request Operations
    // ──────────────────────────────────────

    /**
     * Request a cancellation for an ETA document.
     *
     * @throws ValidationException
     */
    public function requestCancellation(EtaDocument $doc, string $reasonAr, ?string $reasonEn = null): EtaAmendment
    {
        $this->validateCancellable($doc);

        $type = EtaAmendmentType::Cancellation;

        return EtaAmendment::query()->create([
            'tenant_id' => (int) app('tenant.id'),
            'eta_document_id' => $doc->id,
            'type' => $type,
            'status' => EtaAmendmentStatus::Pending,
            'reason_ar' => $reasonAr,
            'reason_en' => $reasonEn,
            'deadline_at' => now()->addDays($type->deadlineDays()),
        ]);
    }

    /**
     * Request an amendment for an ETA document.
     *
     * @throws ValidationException
     */
    public function requestAmendment(
        EtaDocument $doc,
        string $reasonAr,
        ?string $reasonEn = null,
        ?int $correctedInvoiceId = null,
    ): EtaAmendment {
        $this->validateAmendable($doc);

        $type = EtaAmendmentType::Amendment;

        return EtaAmendment::query()->create([
            'tenant_id' => (int) app('tenant.id'),
            'eta_document_id' => $doc->id,
            'corrected_invoice_id' => $correctedInvoiceId,
            'type' => $type,
            'status' => EtaAmendmentStatus::Pending,
            'reason_ar' => $reasonAr,
            'reason_en' => $reasonEn,
            'deadline_at' => now()->addDays($type->deadlineDays()),
        ]);
    }

    // ──────────────────────────────────────
    // Submit & Response
    // ──────────────────────────────────────

    /**
     * Submit an amendment to the ETA API.
     *
     * @throws ValidationException
     */
    public function submit(EtaAmendment $amendment): EtaAmendment
    {
        if (! $amendment->canSubmit()) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot submit amendment with status: {$amendment->status->value}.",
                    "لا يمكن إرسال التعديل بحالة: {$amendment->status->labelAr()}.",
                ],
            ]);
        }

        $amendment->load('etaDocument');
        $document = $amendment->etaDocument;

        if (! $document?->eta_uuid) {
            throw ValidationException::withMessages([
                'eta' => [
                    'Original document has no ETA UUID. It may not have been submitted yet.',
                    'المستند الأصلي ليس له UUID من مصلحة الضرائب. ربما لم يتم إرساله بعد.',
                ],
            ]);
        }

        return DB::transaction(function () use ($amendment, $document): EtaAmendment {
            try {
                $reason = $amendment->reason_en ?? $amendment->reason_ar;

                $response = $amendment->type === EtaAmendmentType::Cancellation
                    ? $this->apiService->cancelDocument($document->eta_uuid, $reason)
                    : $this->apiService->cancelDocument($document->eta_uuid, $reason);

                $amendment->update([
                    'status' => EtaAmendmentStatus::Submitted,
                    'submitted_at' => now(),
                    'submitted_by' => Auth::id(),
                    'response_data' => $response,
                ]);

                return $amendment->refresh();
            } catch (\Throwable $e) {
                $amendment->update([
                    'response_data' => ['error' => $e->getMessage()],
                ]);

                throw $e;
            }
        });
    }

    /**
     * Handle ETA response for an amendment (accepted/rejected).
     */
    public function handleResponse(EtaAmendment $amendment, array $responseData): EtaAmendment
    {
        $etaStatus = $responseData['status'] ?? null;
        $newStatus = match (strtolower((string) $etaStatus)) {
            'accepted', 'approved', 'valid' => EtaAmendmentStatus::Accepted,
            'rejected', 'invalid' => EtaAmendmentStatus::Rejected,
            default => null,
        };

        if ($newStatus === null) {
            return $amendment;
        }

        $amendment->update([
            'status' => $newStatus,
            'response_at' => now(),
            'response_data' => $responseData,
        ]);

        // If an accepted cancellation, update original document status
        if ($newStatus === EtaAmendmentStatus::Accepted
            && $amendment->type === EtaAmendmentType::Cancellation
        ) {
            $amendment->etaDocument?->update([
                'status' => EtaDocumentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
            ]);
        }

        return $amendment->refresh();
    }

    // ──────────────────────────────────────
    // Queries
    // ──────────────────────────────────────

    /**
     * List amendments with filters and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return EtaAmendment::query()
            ->with(['etaDocument.invoice'])
            ->when(
                isset($filters['status']),
                fn ($q) => $q->ofStatus(EtaAmendmentStatus::from($filters['status']))
            )
            ->when(
                isset($filters['type']),
                fn ($q) => $q->ofType(EtaAmendmentType::from($filters['type']))
            )
            ->when(
                isset($filters['document_id']),
                fn ($q) => $q->forDocument((int) $filters['document_id'])
            )
            ->when(
                isset($filters['from']) && isset($filters['to']),
                fn ($q) => $q->whereBetween('created_at', [$filters['from'], $filters['to']])
            )
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get amendments that are past their deadline and still pending.
     *
     * @return Collection<int, EtaAmendment>
     */
    public function overdueAmendments(): Collection
    {
        return EtaAmendment::query()
            ->with(['etaDocument.invoice'])
            ->overdue()
            ->orderBy('deadline_at', 'asc')
            ->get();
    }

    /**
     * Dashboard summary stats for amendments.
     *
     * @return array{pending_count: int, overdue_count: int, acceptance_rate: float, by_type: array<string, int>}
     */
    public function dashboard(): array
    {
        $stats = EtaAmendment::query()
            ->select([
                DB::raw("COUNT(*) FILTER (WHERE status = 'pending') as pending_count"),
                DB::raw("COUNT(*) FILTER (WHERE status = 'pending' AND deadline_at < NOW()) as overdue_count"),
                DB::raw("COUNT(*) FILTER (WHERE status = 'accepted') as accepted_count"),
                DB::raw("COUNT(*) FILTER (WHERE status IN ('accepted', 'rejected')) as resolved_count"),
                DB::raw("COUNT(*) FILTER (WHERE type = 'cancellation') as cancellation_count"),
                DB::raw("COUNT(*) FILTER (WHERE type = 'amendment') as amendment_count"),
            ])
            ->first();

        $acceptanceRate = $stats->resolved_count > 0
            ? round(($stats->accepted_count / $stats->resolved_count) * 100, 1)
            : 0.0;

        return [
            'pending_count' => (int) $stats->pending_count,
            'overdue_count' => (int) $stats->overdue_count,
            'acceptance_rate' => $acceptanceRate,
            'by_type' => [
                'cancellation' => (int) $stats->cancellation_count,
                'amendment' => (int) $stats->amendment_count,
            ],
        ];
    }

    // ──────────────────────────────────────
    // Private: Validation
    // ──────────────────────────────────────

    /**
     * @throws ValidationException
     */
    private function validateCancellable(EtaDocument $doc): void
    {
        $cancellableStatuses = [
            EtaDocumentStatus::Submitted,
            EtaDocumentStatus::Valid,
        ];

        if (! in_array($doc->status, $cancellableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot cancel document with status: {$doc->status->value}. Only submitted or valid documents can be cancelled.",
                    "لا يمكن إلغاء مستند بحالة: {$doc->status->labelAr()}. يمكن إلغاء المستندات المرسلة أو الصالحة فقط.",
                ],
            ]);
        }

        // Check for existing pending cancellation
        $existingPending = EtaAmendment::query()
            ->forDocument($doc->id)
            ->ofType(EtaAmendmentType::Cancellation)
            ->pending()
            ->exists();

        if ($existingPending) {
            throw ValidationException::withMessages([
                'amendment' => [
                    'A pending cancellation request already exists for this document.',
                    'يوجد طلب إلغاء قيد الانتظار بالفعل لهذا المستند.',
                ],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateAmendable(EtaDocument $doc): void
    {
        $amendableStatuses = [
            EtaDocumentStatus::Submitted,
            EtaDocumentStatus::Valid,
        ];

        if (! in_array($doc->status, $amendableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot amend document with status: {$doc->status->value}. Only submitted or valid documents can be amended.",
                    "لا يمكن تعديل مستند بحالة: {$doc->status->labelAr()}. يمكن تعديل المستندات المرسلة أو الصالحة فقط.",
                ],
            ]);
        }

        // Check for existing pending amendment
        $existingPending = EtaAmendment::query()
            ->forDocument($doc->id)
            ->ofType(EtaAmendmentType::Amendment)
            ->pending()
            ->exists();

        if ($existingPending) {
            throw ValidationException::withMessages([
                'amendment' => [
                    'A pending amendment request already exists for this document.',
                    'يوجد طلب تعديل قيد الانتظار بالفعل لهذا المستند.',
                ],
            ]);
        }
    }
}
