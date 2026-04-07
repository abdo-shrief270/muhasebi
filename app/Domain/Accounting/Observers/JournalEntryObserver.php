<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Observers;

use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Support\Facades\Cache;

class JournalEntryObserver
{
    public function created(JournalEntry $entry): void
    {
        $this->invalidateReportCache($entry);
    }

    public function updated(JournalEntry $entry): void
    {
        if ($entry->wasChanged('status')) {
            $this->invalidateReportCache($entry);
        }
    }

    public function deleted(JournalEntry $entry): void
    {
        $this->invalidateReportCache($entry);
    }

    private function invalidateReportCache(JournalEntry $entry): void
    {
        $tenantId = $entry->tenant_id;
        Cache::forget("trial_balance:{$tenantId}");
        Cache::forget("income_statement:{$tenantId}");
        Cache::forget("balance_sheet:{$tenantId}");
        Cache::forget("account_hierarchy:{$tenantId}");
    }
}
