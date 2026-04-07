<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Services\RecurringInvoiceService;
use Illuminate\Console\Command;

class ProcessRecurringInvoicesCommand extends Command
{
    protected $signature = 'invoices:process-recurring';

    protected $description = 'Generate invoices from all due recurring schedules';

    public function handle(RecurringInvoiceService $service): int
    {
        $count = $service->processDue();

        if ($count > 0) {
            $this->info("Generated {$count} recurring invoice(s).");
        } else {
            $this->info('No recurring invoices due.');
        }

        return self::SUCCESS;
    }
}
