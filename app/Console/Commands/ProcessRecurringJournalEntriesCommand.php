<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\Services\RecurringJournalEntryService;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;

class ProcessRecurringJournalEntriesCommand extends Command
{
    protected $signature = 'recurring-je:process';

    protected $description = 'Process all due recurring journal entries across tenants';

    public function handle(RecurringJournalEntryService $service): int
    {
        $totalProcessed = 0;

        Tenant::query()->where('is_active', true)->each(function (Tenant $tenant) use ($service, &$totalProcessed): void {
            app()->instance('tenant.id', $tenant->id);

            $count = $service->processDue();
            $totalProcessed += $count;

            if ($count > 0) {
                $this->info("Tenant {$tenant->id}: generated {$count} journal entry(ies).");
            }
        });

        if ($totalProcessed > 0) {
            $this->info("Total: generated {$totalProcessed} recurring journal entry(ies).");
        } else {
            $this->info('No recurring journal entries due.');
        }

        return self::SUCCESS;
    }
}
