<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\EInvoice\Models\EtaSettings;
use App\Domain\EInvoice\Services\EtaDocumentService;
use Illuminate\Console\Command;

class EtaReconcileCommand extends Command
{
    protected $signature = 'eta:reconcile';

    protected $description = 'Reconcile local ETA documents with the ETA API across all enabled tenants';

    public function handle(EtaDocumentService $documentService): int
    {
        $enabledTenants = EtaSettings::where('is_enabled', true)
            ->whereNotNull('client_id')
            ->pluck('tenant_id');

        if ($enabledTenants->isEmpty()) {
            $this->info('No tenants with ETA enabled.');

            return self::SUCCESS;
        }

        $this->info("Reconciling ETA documents for {$enabledTenants->count()} tenants...");

        foreach ($enabledTenants as $tenantId) {
            app()->instance('tenant.id', $tenantId);

            try {
                $result = $documentService->reconcile();
                $this->line("  Tenant {$tenantId}: matched={$result['matched']}, mismatched={$result['mismatched']}");
            } catch (\Throwable $e) {
                $this->warn("  Tenant {$tenantId}: Error - {$e->getMessage()}");
            }
        }

        $this->info('Reconciliation complete.');

        return self::SUCCESS;
    }
}
