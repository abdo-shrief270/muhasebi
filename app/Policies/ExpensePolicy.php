<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Expense\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_expenses');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->hasPermissionTo('manage_expenses')
            && $user->tenant_id === $expense->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_expenses');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->hasPermissionTo('manage_expenses')
            && $user->tenant_id === $expense->tenant_id
            && in_array($expense->status->value, ['draft', 'rejected'], true);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->hasPermissionTo('manage_expenses')
            && $user->tenant_id === $expense->tenant_id
            && $expense->status->value === 'draft';
    }
}
