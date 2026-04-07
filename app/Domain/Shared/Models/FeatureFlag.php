<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'name', 'description', 'is_enabled_globally', 'enabled_for_plans', 'enabled_for_tenants', 'disabled_for_tenants', 'rollout_percentage'])]
class FeatureFlag extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled_globally' => 'boolean',
            'enabled_for_plans' => 'array',
            'enabled_for_tenants' => 'array',
            'disabled_for_tenants' => 'array',
        ];
    }

    /**
     * Check if this feature is enabled for a specific tenant.
     */
    public function isEnabledFor(int $tenantId, ?int $planId = null): bool
    {
        // Explicit disable takes highest priority
        if (in_array($tenantId, $this->disabled_for_tenants ?? [])) {
            return false;
        }

        // Explicit enable per tenant
        if (in_array($tenantId, $this->enabled_for_tenants ?? [])) {
            return true;
        }

        // Plan-level enable
        if ($planId && in_array($planId, $this->enabled_for_plans ?? [])) {
            return true;
        }

        // Global enable
        if ($this->is_enabled_globally) {
            return true;
        }

        // Gradual rollout (deterministic based on tenant ID)
        if ($this->rollout_percentage) {
            $percentage = (int) $this->rollout_percentage;
            if ($percentage > 0) {
                $hash = crc32("{$this->key}:{$tenantId}");
                return (abs($hash) % 100) < $percentage;
            }
        }

        return false;
    }
}
