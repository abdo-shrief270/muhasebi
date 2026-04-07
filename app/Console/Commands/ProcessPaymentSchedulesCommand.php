<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Services\PaymentWorkflowService;
use Illuminate\Console\Command;

class ProcessPaymentSchedulesCommand extends Command
{
    protected $signature = 'payments:process-scheduled';

    protected $description = 'Process all approved payment schedules due today or earlier';

    public function handle(PaymentWorkflowService $service): int
    {
        $count = $service->processScheduled();

        if ($count > 0) {
            $this->info("Processed {$count} scheduled payment(s).");
        } else {
            $this->info('No scheduled payments to process.');
        }

        return self::SUCCESS;
    }
}
