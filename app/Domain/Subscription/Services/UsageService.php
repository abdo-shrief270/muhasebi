<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;
use App\Domain\Document\Models\StorageQuota;
use App\Domain\Subscription\Models\UsageRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class UsageService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
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

        $storageBytes = StorageQuota::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->value('used_bytes') ?? 0;

        return UsageRecord::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'recorded_at' => now()->toDateString(),
            ],
            [
                'users_count' => $usersCount,
                'clients_count' => $clientsCount,
                'invoices_count' => $invoicesCount,
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

        // Get current subscription and plan limits
        $subscription = $this->subscriptionService->getCurrentSubscription($tenantId);
        $plan = $subscription?->plan;

        $limits = $plan?->limits ?? [];
        $usersLimit = $limits['users'] ?? 0;
        $clientsLimit = $limits['clients'] ?? 0;
        $invoicesLimit = $limits['invoices_per_month'] ?? 0;
        $storageLimit = $limits['storage_bytes'] ?? 0;

        return [
            'users' => $this->buildMetric($record->users_count, $usersLimit),
            'clients' => $this->buildMetric($record->clients_count, $clientsLimit),
            'invoices' => $this->buildMetric($record->invoices_count, $invoicesLimit),
            'storage' => [
                'current_bytes' => $record->storage_bytes,
                'limit_bytes' => $storageLimit,
                'current_human' => $this->bytesForHumans($record->storage_bytes),
                'limit_human' => $storageLimit === -1 ? 'Unlimited' : $this->bytesForHumans($storageLimit),
                'percent' => $storageLimit > 0 ? min(100, (int) round(($record->storage_bytes / $storageLimit) * 100)) : 0,
                'exceeded' => $storageLimit !== -1 && $storageLimit > 0 && $record->storage_bytes >= $storageLimit,
            ],
            'plan' => [
                'name' => $plan?->name_en ?? 'No Plan',
                'name_ar' => $plan?->name_ar ?? 'بدون خطة',
                'slug' => $plan?->slug ?? null,
            ],
        ];
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

        $limits = $subscription->plan->limits ?? [];

        $limitKey = match ($resource) {
            'users' => 'users',
            'clients' => 'clients',
            'invoices' => 'invoices_per_month',
            default => $resource,
        };

        $limit = $limits[$limitKey] ?? 0;

        // -1 means unlimited
        if ($limit === -1) {
            return true;
        }

        // 0 means not allowed
        if ($limit === 0) {
            return false;
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
     * Build a standard usage metric array.
     *
     * @return array<string, mixed>
     */
    private function buildMetric(int $current, int $limit): array
    {
        $isUnlimited = $limit === -1;

        return [
            'current' => $current,
            'limit' => $isUnlimited ? -1 : $limit,
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
            return round($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
