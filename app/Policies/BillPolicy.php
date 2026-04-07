<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\AccountsPayable\Models\Bill;
use App\Models\User;

class BillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_bills');
    }

    public function view(User $user, Bill $bill): bool
    {
        return $user->hasPermissionTo('manage_bills')
            && $user->tenant_id === $bill->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_bills');
    }

    public function update(User $user, Bill $bill): bool
    {
        return $user->hasPermissionTo('manage_bills')
            && $user->tenant_id === $bill->tenant_id;
    }

    public function delete(User $user, Bill $bill): bool
    {
        return $user->hasPermissionTo('manage_bills')
            && $user->tenant_id === $bill->tenant_id;
    }
}
