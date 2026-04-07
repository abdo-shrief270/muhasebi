<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\EInvoice\Enums\EtaDocumentStatus;
use App\Domain\EInvoice\Models\EtaDocument;
use App\Domain\EInvoice\Services\EtaDocumentService;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;

class EtaCheckStatusCommand extends Command
{
    protected $signature = 'eta:check-status {--hours=48 : Check documents submitted within this many hours}';

    protected $description = 'Check ETA status for submitted documents across all tenants';

    public function handle(EtaDocumentService $documentService): int
    {
        $hours = (int) $this->option('hours');

        $documents = EtaDocument::withoutGlobalScopes()
            ->where('status', EtaDocumentStatus::Submitted)
            ->whereNotNull('eta_uuid')
            ->where('submitted_at', '>=', now()->subHours($hours))
            ->get();

        if ($documents->isEmpty()) {
            $this->info('No submitted documents to check.');

            return self::SUCCESS;
        }

        $this->info("Checking status for {$documents->count()} submitted documents...");

        $updated = 0;
        $errors = 0;

        foreach ($documents as $doc) {
            // Set tenant context
            app()->instance('tenant.id', $doc->tenant_id);

            try {
                $oldStatus = $doc->status;
                $documentService->checkStatus($doc);

                if ($doc->fresh()->status !== $oldStatus) {
                    $updated++;
                    $this->line("  [{$doc->internal_id}] {$oldStatus->value} → {$doc->fresh()->status->value}");
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("  [{$doc->internal_id}] Error: {$e->getMessage()}");
            }
        }

        $this->info("Done. Updated: {$updated}, Errors: {$errors}");

        return self::SUCCESS;
    }
}
