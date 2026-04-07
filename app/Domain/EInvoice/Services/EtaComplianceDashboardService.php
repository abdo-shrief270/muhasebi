<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Services;

use App\Domain\EInvoice\Enums\EtaDocumentStatus;
use App\Domain\EInvoice\Models\EtaDocument;
use App\Domain\EInvoice\Models\EtaSettings;
use App\Domain\EInvoice\Models\EtaSubmission;
use App\Domain\Billing\Models\Invoice;
use Illuminate\Support\Facades\DB;

class EtaComplianceDashboardService
{
    public function __construct(
        private readonly EtaDocumentService $documentService,
        private readonly EtaSettingsService $settingsService,
    ) {}

    public function dashboard(): array
    {
        $tenantId = (int) app('tenant.id');

        return [
            'overview' => $this->overview($tenantId),
            'documents_by_status' => $this->documentsByStatus($tenantId),
            'submission_stats' => $this->submissionStats($tenantId),
            'compliance_rate' => $this->complianceRate($tenantId),
            'common_errors' => $this->commonErrors($tenantId),
            'stuck_documents' => $this->stuckDocuments($tenantId),
            'recent_activity' => $this->recentActivity($tenantId),
            'auth_health' => $this->authHealth($tenantId),
            'monthly_trend' => $this->monthlyTrend($tenantId),
            'generated_at' => now()->format('Y-m-d H:i'),
        ];
    }

    private function overview(int $tenantId): array
    {
        $base = EtaDocument::where('tenant_id', $tenantId);
        $totalDocuments = (clone $base)->count();
        $validDocuments = (clone $base)->where('status', EtaDocumentStatus::Valid)->count();
        $pendingDocuments = (clone $base)->whereIn('status', [EtaDocumentStatus::Prepared, EtaDocumentStatus::Submitted])->count();
        $failedDocuments = (clone $base)->whereIn('status', [EtaDocumentStatus::Invalid, EtaDocumentStatus::Rejected])->count();
        $cancelledDocuments = (clone $base)->where('status', EtaDocumentStatus::Cancelled)->count();

        return [
            'total_documents' => $totalDocuments,
            'valid' => $validDocuments,
            'pending' => $pendingDocuments,
            'failed' => $failedDocuments,
            'cancelled' => $cancelledDocuments,
            'success_rate' => $totalDocuments > 0
                ? round(($validDocuments / $totalDocuments) * 100, 1)
                : 0,
        ];
    }

    private function documentsByStatus(int $tenantId): array
    {
        return EtaDocument::where('tenant_id', $tenantId)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    private function submissionStats(int $tenantId): array
    {
        $totals = EtaSubmission::where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->selectRaw('COALESCE(SUM(accepted_count), 0) as total_accepted')
            ->selectRaw('COALESCE(SUM(rejected_count), 0) as total_rejected')
            ->first();

        return [
            'total_submissions' => (int) $totals->total,
            'completed' => (int) $totals->completed,
            'failed' => (int) $totals->failed,
            'total_accepted' => (int) $totals->total_accepted,
            'total_rejected' => (int) $totals->total_rejected,
            'acceptance_rate' => ($totals->total_accepted + $totals->total_rejected) > 0
                ? round(($totals->total_accepted / ($totals->total_accepted + $totals->total_rejected)) * 100, 1)
                : 0,
        ];
    }

    private function complianceRate(int $tenantId): array
    {
        $base = Invoice::where('tenant_id', $tenantId)->whereNotIn('status', ['draft', 'cancelled']);

        $eligibleInvoices = (clone $base)->count();
        $withEtaDocument = (clone $base)->whereHas('etaDocument')->count();
        $withValidEta = (clone $base)->whereHas('etaDocument', fn ($q) => $q->where('status', EtaDocumentStatus::Valid))->count();

        return [
            'eligible_invoices' => $eligibleInvoices,
            'with_eta_document' => $withEtaDocument,
            'with_valid_eta' => $withValidEta,
            'coverage_rate' => $eligibleInvoices > 0
                ? round(($withEtaDocument / $eligibleInvoices) * 100, 1)
                : 0,
            'validation_rate' => $withEtaDocument > 0
                ? round(($withValidEta / $withEtaDocument) * 100, 1)
                : 0,
            'missing_count' => $eligibleInvoices - $withEtaDocument,
        ];
    }

    private function commonErrors(int $tenantId): array
    {
        $documents = EtaDocument::where('tenant_id', $tenantId)
            ->whereIn('status', [EtaDocumentStatus::Invalid, EtaDocumentStatus::Rejected])
            ->whereNotNull('errors')
            ->get(['errors']);

        $errorCounts = [];

        foreach ($documents as $doc) {
            $errors = is_array($doc->errors) ? $doc->errors : [];

            foreach ($errors as $error) {
                $message = is_string($error) ? $error : ($error['message'] ?? $error['error'] ?? json_encode($error));
                $errorCounts[$message] = ($errorCounts[$message] ?? 0) + 1;
            }
        }

        arsort($errorCounts);

        return collect($errorCounts)
            ->take(10)
            ->map(fn ($count, $error) => ['error' => $error, 'count' => $count])
            ->values()
            ->toArray();
    }

    private function stuckDocuments(int $tenantId): array
    {
        return EtaDocument::where('tenant_id', $tenantId)
            ->where('status', EtaDocumentStatus::Submitted)
            ->where('submitted_at', '<', now()->subHours(24))
            ->with('invoice:id,invoice_number,client_id')
            ->get(['id', 'invoice_id', 'eta_uuid', 'internal_id', 'submitted_at'])
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'invoice_number' => $doc->invoice?->invoice_number ?? $doc->internal_id,
                'eta_uuid' => $doc->eta_uuid,
                'submitted_at' => $doc->submitted_at?->toDateTimeString(),
                'hours_stuck' => $doc->submitted_at ? now()->diffInHours($doc->submitted_at) : null,
            ])
            ->toArray();
    }

    private function recentActivity(int $tenantId): array
    {
        return EtaDocument::where('tenant_id', $tenantId)
            ->with('invoice:id,invoice_number')
            ->latest('updated_at')
            ->take(20)
            ->get(['id', 'invoice_id', 'internal_id', 'status', 'eta_uuid', 'submitted_at', 'updated_at'])
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'invoice_number' => $doc->invoice?->invoice_number ?? $doc->internal_id,
                'status' => $doc->status->value,
                'eta_uuid' => $doc->eta_uuid,
                'submitted_at' => $doc->submitted_at?->toDateTimeString(),
                'updated_at' => $doc->updated_at?->toDateTimeString(),
            ])
            ->toArray();
    }

    private function authHealth(int $tenantId): array
    {
        $settings = EtaSettings::where('tenant_id', $tenantId)->first();

        if (! $settings) {
            return [
                'configured' => false,
                'enabled' => false,
                'environment' => null,
                'token_valid' => false,
                'token_expires_at' => null,
            ];
        }

        return [
            'configured' => ! empty($settings->client_id) && ! empty($settings->client_secret),
            'enabled' => (bool) $settings->is_enabled,
            'environment' => $settings->environment,
            'token_valid' => $settings->isTokenValid(),
            'token_expires_at' => $settings->token_expires_at?->toDateTimeString(),
            'minutes_until_expiry' => $settings->token_expires_at
                ? max(0, now()->diffInMinutes($settings->token_expires_at, false))
                : 0,
        ];
    }

    private function monthlyTrend(int $tenantId): array
    {
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthStart = $date->startOfMonth()->toDateString();
            $monthEnd = $date->endOfMonth()->toDateString();
            $label = $date->format('Y-m');

            $stats = EtaDocument::where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) as valid")
                ->selectRaw("SUM(CASE WHEN status IN ('invalid', 'rejected') THEN 1 ELSE 0 END) as failed")
                ->first();

            $months[] = [
                'month' => $label,
                'submitted' => (int) $stats->total,
                'valid' => (int) $stats->valid,
                'failed' => (int) $stats->failed,
            ];
        }

        return $months;
    }

    public function bulkRetry(array $documentIds = []): array
    {
        $query = EtaDocument::query()
            ->whereIn('status', [EtaDocumentStatus::Rejected, EtaDocumentStatus::Invalid])
            ->with('invoice');

        if (! empty($documentIds)) {
            $query->whereIn('id', $documentIds);
        }

        $documents = $query->get();
        $prepared = 0;
        $submitted = 0;
        $errors = [];

        foreach ($documents as $doc) {
            if (! $doc->invoice) {
                $errors[] = ['id' => $doc->id, 'error' => 'Invoice not found'];

                continue;
            }

            try {
                $this->documentService->prepare($doc->invoice);
                $prepared++;

                $this->documentService->submit($doc->invoice);
                $submitted++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'id' => $doc->id,
                    'invoice_number' => $doc->internal_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return compact('prepared', 'submitted', 'errors');
    }

    public function bulkStatusCheck(): array
    {
        $documents = EtaDocument::query()
            ->where('status', EtaDocumentStatus::Submitted)
            ->whereNotNull('eta_uuid')
            ->get();

        $checked = 0;
        $updated = 0;
        $errors = [];

        foreach ($documents as $doc) {
            try {
                $oldStatus = $doc->status;
                $this->documentService->checkStatus($doc);
                $checked++;

                if ($doc->fresh()->status !== $oldStatus) {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'id' => $doc->id,
                    'eta_uuid' => $doc->eta_uuid,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return compact('checked', 'updated', 'errors');
    }
}
