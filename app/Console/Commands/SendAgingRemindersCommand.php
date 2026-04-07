<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Services\AgingReminderService;
use App\Domain\Shared\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;

class SendAgingRemindersCommand extends Command
{
    protected $signature = 'invoices:aging-reminders {--tenant= : Process only this tenant ID}';

    protected $description = 'Send aging reminders (30/60/90 days overdue) for all tenants';

    public function handle(AgingReminderService $service): int
    {
        $tenantQuery = Tenant::where('status', TenantStatus::Active);

        if ($tenantId = $this->option('tenant')) {
            $tenantQuery->where('id', $tenantId);
        }

        $tenants = $tenantQuery->pluck('id');

        if ($tenants->isEmpty()) {
            $this->info('No active tenants found.');

            return self::SUCCESS;
        }

        $this->info("Processing aging reminders for {$tenants->count()} tenants...");

        $totalSent = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($tenants as $tenantId) {
            $result = $service->processForTenant($tenantId);
            $totalSent += $result['sent'];
            $totalSkipped += $result['skipped'];
            $totalErrors += count($result['errors']);

            if ($result['sent'] > 0 || ! empty($result['errors'])) {
                $this->line("  Tenant {$tenantId}: sent={$result['sent']}, skipped={$result['skipped']}, errors=".count($result['errors']));
            }
        }

        $this->info("Done. Sent: {$totalSent}, Skipped: {$totalSkipped}, Errors: {$totalErrors}");

        return self::SUCCESS;
    }
}
