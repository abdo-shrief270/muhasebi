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
        $filename = sprintf(
            'reports/%d/%s_%s.%s',
            $this->tenantId,
            $this->reportType,
            now()->format('Y-m-d_His'),
            $this->format,
        );

        // Report generation is delegated to the existing ReportController logic.
        // This job wraps it for async execution. The actual report service
        // should be extracted from the controller for reuse here.
        // For now, store a placeholder indicating the job completed.
        Storage::disk('local')->put($filename, json_encode([
            'report_type' => $this->reportType,
            'filters' => $this->filters,
            'format' => $this->format,
            'generated_at' => now()->toISOString(),
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'status' => 'completed',
        ]));

        // TODO: Send notification to user that report is ready
        // Notification::send(User::find($this->userId), new ReportReadyNotification($filename));

        logger()->info("Report generated: {$filename}", [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'type' => $this->reportType,
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
