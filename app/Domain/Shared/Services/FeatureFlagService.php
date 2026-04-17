<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;

/**
 * Feature flag service for toggling features per tenant/plan.
 *
 * Usage:
 *   FeatureFlagService::isEnabled('client_portal', tenantId: 5, planId: 2)
 *   FeatureFlagService::check('eta_integration') // uses current tenant context
 */
class FeatureFlagService
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Check if a feature is enabled for a specific tenant.
     */
    public static function isEnabled(string $key, ?int $tenantId = null, ?int $planId = null): bool
    {
        $tenantId = $tenantId ?? app('tenant.id');
        if (! $tenantId) {
            return false;
        }

        $flag = self::getFlag($key);
        if (! $flag) {
            return false;
        }

        return $flag->isEnabledFor($tenantId, $planId);
    }

    /**
     * Check using current tenant context (convenience method).
     */
    public static function check(string $key): bool
    {
        $tenantId = app('tenant.id');
        $planId = null;

        // Try to resolve plan from current subscription
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        if ($tenant) {
            $planId = $tenant->subscriptions()
                ->where('status', 'active')
                ->orWhere('status', 'trial')
                ->value('plan_id');
        }

        return self::isEnabled($key, $tenantId, $planId);
    }

    /**
     * Get all flags with their status for a tenant (for frontend consumption).
     */
    public static function getAllForTenant(int $tenantId, ?int $planId = null): array
    {
        return Cache::remember("feature_flags:tenant:{$tenantId}", self::CACHE_TTL, function () use ($tenantId, $planId) {
            return FeatureFlag::all()->mapWithKeys(function (FeatureFlag $flag) use ($tenantId, $planId) {
                return [$flag->key => $flag->isEnabledFor($tenantId, $planId)];
            })->toArray();
        });
    }

    /**
     * Get a single flag (cached).
     */
    private static function getFlag(string $key): ?FeatureFlag
    {
        return Cache::remember("feature_flag:{$key}", self::CACHE_TTL, function () use ($key) {
            return FeatureFlag::where('key', $key)->first();
        });
    }

    /**
     * Clear all feature flag caches (call after admin updates).
     *
     * Invoked automatically by FeatureFlagObserver on save/delete and
     * may also be called manually from console/admin actions.
     */
    public static function clearCache(): void
    {
        $flags = FeatureFlag::all();
        foreach ($flags as $flag) {
            Cache::forget("feature_flag:{$flag->key}");
        }
        // Per-tenant rollups expire via TTL; most cache stores don't
        // support pattern deletion so we rely on the 5-minute window.
    }
}
