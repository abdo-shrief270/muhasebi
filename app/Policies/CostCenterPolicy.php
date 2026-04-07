<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\CostCenter;
use App\Models\User;

class CostCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_cost_centers');
    }

    public function view(User $user, CostCenter $costCenter): bool
    {
        return $user->hasPermissionTo('manage_cost_centers')
            && $user->tenant_id === $costCenter->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_cost_centers');
    }

    public function update(User $user, CostCenter $costCenter): bool
    {
        return $user->hasPermissionTo('manage_cost_centers')
            && $user->tenant_id === $costCenter->tenant_id;
    }

    public function delete(User $user, CostCenter $costCenter): bool
    {
        return $user->hasPermissionTo('manage_cost_centers')
            && $user->tenant_id === $costCenter->tenant_id;
    }
}
