<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\AccountsPayable\Models\Vendor;
use App\Models\User;

class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_vendors');
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('manage_vendors')
            && $user->tenant_id === $vendor->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_vendors');
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('manage_vendors')
            && $user->tenant_id === $vendor->tenant_id;
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->hasPermissionTo('manage_vendors')
            && $user->tenant_id === $vendor->tenant_id;
    }
}
