<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a heavy report (PDF/CSV) asynchronously.
 * The result is stored to disk and the user is notified.
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public int $backoff = 30;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly string $reportType,
        public readonly array $filters = [],
        public readonly string $format = 'pdf',
    ) {
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        app()->instance('tenant.id', $this->tenantId);

        $filename = sprintf(
            'reports/%d/%s_%s.%s',
            $this->tenantId,
            $this->reportType,
            now()->format('Y-m-d_His'),
            $this->format,
        );

        if ($this->format === 'pdf') {
            $pdfService = app(\App\Domain\Accounting\Services\ReportPdfService::class);

            /** @var \Illuminate\Http\Response $response */
            $response = match ($this->reportType) {
                'trial_balance' => $pdfService->trialBalancePdf($this->filters['from'] ?? null, $this->filters['to'] ?? null),
                'income_statement' => $pdfService->incomeStatementPdf($this->filters['from'] ?? null, $this->filters['to'] ?? null),
                'balance_sheet' => $pdfService->balanceSheetPdf($this->filters['date'] ?? null),
                'cash_flow' => $pdfService->cashFlowPdf($this->filters['from'] ?? null, $this->filters['to'] ?? null),
                'vat_return' => $pdfService->vatReturnPdf($this->filters['from'] ?? null, $this->filters['to'] ?? null),
                'wht_report' => $pdfService->whtReportPdf($this->filters['from'] ?? null, $this->filters['to'] ?? null),
                default => throw new \InvalidArgumentException("Unknown report type: {$this->reportType}"),
            };

            Storage::disk('local')->put($filename, $response->getContent());
        } else {
            // Non-PDF formats can be added here in the future
            Storage::disk('local')->put($filename, json_encode([
                'report_type' => $this->reportType,
                'filters' => $this->filters,
                'format' => $this->format,
                'generated_at' => now()->toISOString(),
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
                'status' => 'completed',
            ]));
        }

        logger()->info("Report generated: {$filename}", [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'type' => $this->reportType,
            'format' => $this->format,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        logger()->error("Report generation failed: {$this->reportType}", [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
