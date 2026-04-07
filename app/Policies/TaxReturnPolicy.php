<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tax\Models\TaxReturn;
use App\Models\User;

class TaxReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_tax');
    }

    public function view(User $user, TaxReturn $taxReturn): bool
    {
        return $user->hasPermissionTo('manage_tax')
            && $user->tenant_id === $taxReturn->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_tax');
    }

    public function update(User $user, TaxReturn $taxReturn): bool
    {
        return $user->hasPermissionTo('manage_tax')
            && $user->tenant_id === $taxReturn->tenant_id;
    }

    public function delete(User $user, TaxReturn $taxReturn): bool
    {
        return $user->hasPermissionTo('manage_tax')
            && $user->tenant_id === $taxReturn->tenant_id;
    }
}
