<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_accounts');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->hasPermissionTo('manage_accounts')
            && $user->tenant_id === $account->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_accounts');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasPermissionTo('manage_accounts')
            && $user->tenant_id === $account->tenant_id;
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->hasPermissionTo('manage_accounts')
            && $user->tenant_id === $account->tenant_id;
    }
}
