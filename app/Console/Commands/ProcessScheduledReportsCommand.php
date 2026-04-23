<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\Services\ReportSchedulerService;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;

class ProcessScheduledReportsCommand extends Command
{
    protected $signature = 'reports:process-scheduled';

    protected $description = 'Process all due scheduled reports and email them to recipients';

    public function handle(ReportSchedulerService $service): int
    {
        $totalProcessed = 0;

        Tenant::query()->accessible()->each(function (Tenant $tenant) use ($service, &$totalProcessed): void {
            app()->instance('tenant.id', $tenant->id);

            $count = $service->processDue();
            $totalProcessed += $count;

            if ($count > 0) {
                $this->info("Tenant {$tenant->id}: sent {$count} scheduled report(s).");
            }
        });

        if ($totalProcessed > 0) {
            $this->info("Total: sent {$totalProcessed} scheduled report(s).");
        } else {
            $this->info('No scheduled reports due.');
        }

        return self::SUCCESS;
    }
}
