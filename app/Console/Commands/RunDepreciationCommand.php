<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\FixedAssets\Services\DepreciationService;
use App\Domain\Tenant\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunDepreciationCommand extends Command
{
    protected $signature = 'assets:depreciate {--month= : Period end date (Y-m-d), defaults to end of current month}';

    protected $description = 'Run monthly depreciation for all tenants';

    public function handle(DepreciationService $service): int
    {
        $periodEnd = $this->option('month')
            ? Carbon::parse($this->option('month'))->endOfMonth()->toDateString()
            : now()->endOfMonth()->toDateString();

        $this->info("Running depreciation through {$periodEnd}...");

        $tenants = Tenant::where('status', 'active')->pluck('id');
        $totalAssets = 0;
        $totalAmount = '0.00';

        foreach ($tenants as $tenantId) {
            app()->instance('tenant.id', $tenantId);
            try {
                $result = $service->runMonthly($tenantId, $periodEnd);
                $totalAssets += $result['count'];
                $totalAmount = bcadd($totalAmount, $result['total_amount'], 2);
                if ($result['count'] > 0) {
                    $this->line("  Tenant {$tenantId}: {$result['count']} assets, {$result['total_amount']}");
                }
            } catch (\Throwable $e) {
                $this->error("  Tenant {$tenantId} failed: {$e->getMessage()}");
            } finally {
                app()->forgetInstance('tenant.id');
            }
        }

        $this->info("Done: {$totalAssets} assets depreciated, total: {$totalAmount}");

        return self::SUCCESS;
    }
}
