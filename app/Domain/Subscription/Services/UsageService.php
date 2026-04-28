<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Services;

use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;
use App\Domain\Document\Models\Document;
use App\Domain\Document\Models\StorageQuota;
use App\Domain\Shared\Models\ApiUsageMeter;
use App\Domain\Subscription\Models\UsageRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class UsageService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly AddOnService $addOnService,
    ) {}

    /**
     * Record current usage snapshot for a tenant.
     * Creates or updates the UsageRecord for today.
     */
    public function recordUsage(?int $tenantId = null): UsageRecord
    {
        $tenantId ??= (int) app('tenant.id');

        $usersCount = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $clientsCount = Client::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $invoicesCount = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $billsCount = Bill::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $journalEntriesCount = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // BankStatementLine doesn't carry tenant_id directly — scope through
        // its parent BankReconciliation via the `reconciliation` relation.
        $bankImportsCount = BankStatementLine::query()
            ->whereHas('reconciliation', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId))
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $documentsCount = Document::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $quota = StorageQuota::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        $storageBytes = (int) ($quota->used_bytes ?? 0);
        // Prefer the StorageQuota.used_files counter when present — it's
        // maintained in sync with file uploads — otherwise fall back to
        // the Document table count we just computed above.
        $documentsCount = (int) ($quota->used_files ?? $documentsCount);

        // api_calls_count is rolled up from ApiUsageMeter — that table is
        // the merged buffer + DB total maintained by MeterApiUsage middleware
        // and flushed nightly by usage:flush-buffers.
        $apiCallsCount = (int) (ApiUsageMeter::query()
            ->where('tenant_id', $tenantId)
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->sum('api_calls') ?? 0);

        return UsageRecord::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'recorded_at' => now()->toDateString(),
            ],
            [
                'users_count' => $usersCount,
                'clients_count' => $clientsCount,
                'invoices_count' => $invoicesCount,
                'bills_count' => $billsCount,
                'journal_entries_count' => $journalEntriesCount,
                'bank_imports_count' => $bankImportsCount,
                'documents_count' => $documentsCount,
                'api_calls_count' => $apiCallsCount,
                'storage_bytes' => $storageBytes,
            ],
        );
    }

    /**
     * Get current usage with plan limits and percentages.
     *
     * @return array<string, mixed>
     */
    public function getUsage(?int $tenantId = null): array
    {
        $tenantId ??= (int) app('tenant.id');

        // Record fresh usage
        $record = $this->recordUsage($tenantId);

        // Effective limits = plan.limits + sum of active boost add-ons.
        $subscription = $this->subscriptionService->getCurrentSubscription($tenantId);
        $plan = $subscription?->plan;

        $limits = $this->addOnService->getEffectiveLimits($tenantId);
        $boostBreakdown = $this->addOnService->getBoostBreakdown($tenantId);

        // All limit keys follow the seeder/test convention `max_*`. Until
        // 2026-04-27 the service read `users`/`clients`/... unprefixed,
        // which silently returned 0 (= "no plan limit") on every tenant.
        // Restoring the prefix means quotas now actually enforce.
        $usersLimit = (int) ($limits['max_users'] ?? 0);
        $clientsLimit = (int) ($limits['max_clients'] ?? 0);
        $invoicesLimit = (int) ($limits['max_invoices_per_month'] ?? 0);
        $billsLimit = (int) ($limits['max_bills_per_month'] ?? 0);
        $journalEntriesLimit = (int) ($limits['max_journal_entries_per_month'] ?? 0);
        $bankImportsLimit = (int) ($limits['max_bank_imports_per_month'] ?? 0);
        $documentsLimit = (int) ($limits['max_documents'] ?? 0);
        $apiCallsLimit = (int) ($limits['max_api_calls_per_month'] ?? 0);
        $storageLimit = (int) ($limits['max_storage_bytes'] ?? 0);

        return [
            'users' => $this->buildMetric($record->users_count, $usersLimit, $boostBreakdown['max_users'] ?? 0),
            'clients' => $this->buildMetric($record->clients_count, $clientsLimit, $boostBreakdown['max_clients'] ?? 0),
            'invoices' => $this->buildMetric($record->invoices_count, $invoicesLimit, $boostBreakdown['max_invoices_per_month'] ?? 0),
            'bills' => $this->buildMetric($record->bills_count, $billsLimit, $boostBreakdown['max_bills_per_month'] ?? 0),
            'journal_entries' => $this->buildMetric($record->journal_entries_count, $journalEntriesLimit, $boostBreakdown['max_journal_entries_per_month'] ?? 0),
            'bank_imports' => $this->buildMetric($record->bank_imports_count, $bankImportsLimit, $boostBreakdown['max_bank_imports_per_month'] ?? 0),
            'documents' => $this->buildMetric($record->documents_count, $documentsLimit, $boostBreakdown['max_documents'] ?? 0),
            'api_calls' => $this->buildMetric($record->api_calls_count, $apiCallsLimit, $boostBreakdown['max_api_calls_per_month'] ?? 0),
            'storage' => [
                'current_bytes' => $record->storage_bytes,
                'limit_bytes' => $storageLimit,
                'current_human' => $this->bytesForHumans($record->storage_bytes),
                'limit_human' => $storageLimit === -1 ? 'Unlimited' : $this->bytesForHumans($storageLimit),
                'percent' => $storageLimit > 0 ? min(100, (int) round(($record->storage_bytes / $storageLimit) * 100)) : 0,
                'exceeded' => $storageLimit !== -1 && $storageLimit > 0 && $record->storage_bytes >= $storageLimit,
                'boost_contribution' => $boostBreakdown['max_storage_bytes'] ?? 0,
            ],
            'plan' => [
                'name' => $plan?->name_en ?? 'No Plan',
                'name_ar' => $plan?->name_ar ?? 'بدون خطة',
                'slug' => $plan?->slug ?? null,
            ],
            'projections' => $this->buildProjections($tenantId, [
                'users_count' => $usersLimit,
                'clients_count' => $clientsLimit,
                'invoices_count' => $invoicesLimit,
                'bills_count' => $billsLimit,
                'journal_entries_count' => $journalEntriesLimit,
                'bank_imports_count' => $bankImportsLimit,
                'documents_count' => $documentsLimit,
                'storage_bytes' => $storageLimit,
            ]),
        ];
    }

    /**
     * Linear-trend projection of when each metered resource will hit its
     * limit, based on the slope from the oldest sample to the newest in
     * the recent window. Returns ISO date strings or null when:
     *  - the limit is unlimited (-1) or zero,
     *  - usage isn't growing, or
     *  - we don't have at least 2 samples to fit a slope.
     *
     * Plain enough math that we can stay in PHP without pulling in stats
     * dependencies — the UI only needs a rough "you'll hit this in ~12 days"
     * indicator, not statistical rigor.
     *
     * @param  array<string, int>  $limits
     * @return array<string, ?string>
     */
    private function buildProjections(int $tenantId, array $limits, int $sampleDays = 14): array
    {
        $samples = UsageRecord::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('recorded_at', '>=', now()->subDays($sampleDays)->toDateString())
            ->orderBy('recorded_at')
            ->get();

        $projections = array_fill_keys(array_keys($limits), null);

        if ($samples->count() < 2) {
            return $projections;
        }

        $first = $samples->first();
        $last = $samples->last();
        $daysSpan = max(1, (int) Carbon::parse($first->recorded_at)->diffInDays(Carbon::parse($last->recorded_at)));

        foreach ($limits as $field => $limit) {
            if ($limit === -1 || $limit <= 0) {
                continue;
            }
            $current = (int) ($last->{$field} ?? 0);
            if ($current >= $limit) {
                continue; // already exceeded — projection is moot
            }
            $delta = $current - (int) ($first->{$field} ?? 0);
            if ($delta <= 0) {
                continue; // flat or decreasing — no exhaust
            }
            $perDay = $delta / $daysSpan;
            if ($perDay <= 0) {
                continue;
            }
            $daysUntil = (int) ceil(($limit - $current) / $perDay);
            // Cap at 365 — beyond a year, "no near-term exhaust" reads better.
            if ($daysUntil > 365) {
                continue;
            }
            $projections[$field] = now()->addDays($daysUntil)->toDateString();
        }

        return $projections;
    }

    /**
     * Check if the tenant can add one more of the specified resource.
     *
     * @param  string  $resource  One of: users, clients, invoices
     */
    public function checkLimit(string $resource): bool
    {
        $tenantId = (int) app('tenant.id');
        $subscription = $this->subscriptionService->getCurrentSubscription($tenantId);

        if (! $subscription || ! $subscription->plan) {
            return false;
        }

        // Effective limit = plan + active boost add-ons.
        $limits = $this->addOnService->getEffectiveLimits($tenantId);

        $limitKey = match ($resource) {
            'users' => 'max_users',
            'clients' => 'max_clients',
            'invoices' => 'max_invoices_per_month',
            'bills' => 'max_bills_per_month',
            'journal_entries' => 'max_journal_entries_per_month',
            'bank_imports' => 'max_bank_imports_per_month',
            'documents' => 'max_documents',
            'api_calls' => 'max_api_calls_per_month',
            default => $resource,
        };

        $limit = (int) ($limits[$limitKey] ?? 0);

        if ($limit === -1) {
            return true; // unlimited
        }

        if ($limit === 0) {
            return false; // not allowed by plan
        }

        $currentCount = match ($resource) {
            'users' => User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->count(),
            'clients' => Client::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->count(),
            'invoices' => Invoice::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'bills' => Bill::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'journal_entries' => JournalEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'documents' => (int) (StorageQuota::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->value('used_files') ?? 0),
            default => 0,
        };

        return $currentCount < $limit;
    }

    /**
     * Enforce a usage limit. Throws ValidationException if limit is exceeded.
     *
     * @param  string  $resource  One of: users, clients, invoices
     *
     * @throws ValidationException
     */
    public function enforceLimit(string $resource): void
    {
        if ($this->checkLimit($resource)) {
            return;
        }

        $messages = match ($resource) {
            'users' => [
                'You have reached the maximum number of users for your current plan.',
                'لقد وصلت للحد الأقصى من المستخدمين في خطتك الحالية.',
            ],
            'clients' => [
                'You have reached the maximum number of clients for your current plan.',
                'لقد وصلت للحد الأقصى من العملاء في خطتك الحالية.',
            ],
            'invoices' => [
                'You have reached the maximum number of invoices for this month on your current plan.',
                'لقد وصلت للحد الأقصى من الفواتير لهذا الشهر في خطتك الحالية.',
            ],
            default => [
                "You have reached the limit for {$resource} on your current plan.",
                "لقد وصلت للحد الأقصى من {$resource} في خطتك الحالية.",
            ],
        };

        throw ValidationException::withMessages([
            'limit' => $messages,
        ]);
    }

    /**
     * Get usage history for a tenant over the last N days.
     */
    public function getUsageHistory(int $days = 30, ?int $tenantId = null): Collection
    {
        $tenantId ??= (int) app('tenant.id');

        return UsageRecord::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('recorded_at', '>=', now()->subDays($days)->toDateString())
            ->orderBy('recorded_at', 'desc')
            ->get();
    }

    /**
     * Build a standard usage metric array. `boost_contribution` is the
     * portion of the limit that came from active add-ons — the UI uses it
     * to surface "10 base + 5 add-on" breakdowns.
     *
     * @return array<string, mixed>
     */
    private function buildMetric(int $current, int $limit, int $boostContribution = 0): array
    {
        $isUnlimited = $limit === -1;
        $base = max(0, $limit - $boostContribution);

        return [
            'current' => $current,
            'limit' => $isUnlimited ? -1 : $limit,
            'base_limit' => $isUnlimited ? -1 : $base,
            'boost_contribution' => $boostContribution,
            'percent' => ($isUnlimited || $limit === 0) ? 0 : min(100, (int) round(($current / $limit) * 100)),
            'exceeded' => ! $isUnlimited && $limit > 0 && $current >= $limit,
        ];
    }

    /**
     * Convert bytes to a human-readable string.
     */
    private function bytesForHumans(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }
}
