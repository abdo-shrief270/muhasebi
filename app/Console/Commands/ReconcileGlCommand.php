<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\Services\GlReconciliationService;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly GL invariant check. Iterates active tenants, runs reconciliation,
 * and logs anomalies. Intentionally read-only — does not attempt to fix
 * discrepancies, only surfaces them so a human can investigate.
 */
class ReconcileGlCommand extends Command
{
    protected $signature = 'gl:reconcile {--tenant= : Limit to a specific tenant id}';

    protected $description = 'Verify General-Ledger invariants for each active tenant and log any anomalies.';

    public function handle(GlReconciliationService $service): int
    {
        $tenantId = $this->option('tenant');

        $query = Tenant::query()->accessible();
        if ($tenantId) {
            $query->where('id', (int) $tenantId);
        }

        $failing = 0;
        $total = 0;

        $query->each(function (Tenant $tenant) use ($service, &$failing, &$total): void {
            $total++;
            app()->instance('tenant.id', $tenant->id);

            $report = $service->reconcileTenant($tenant->id);

            if ($report['ok']) {
                $this->line(sprintf(
                    'Tenant %d (%s): %d entries, balanced.',
                    $tenant->id,
                    $tenant->slug,
                    $report['entries_checked'],
                ));

                return;
            }

            $failing++;
            $this->error(sprintf(
                'Tenant %d (%s): RECONCILIATION FAILED — variance=%s, unbalanced=%d, line_mismatches=%d',
                $tenant->id,
                $tenant->slug,
                $report['variance'],
                count($report['unbalanced_entries']),
                count($report['line_sum_mismatches']),
            ));

            Log::channel('stack')->error('GL reconciliation anomaly', $report);
        });

        if ($failing > 0) {
            $this->error(sprintf('%d of %d tenants failed reconciliation.', $failing, $total));

            return self::FAILURE;
        }

        $this->info(sprintf('All %d tenants reconciled cleanly.', $total));

        return self::SUCCESS;
    }
}
