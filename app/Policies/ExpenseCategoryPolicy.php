<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Expense\Models\ExpenseCategory;
use App\Models\User;

class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_expenses');
    }

    public function view(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->hasPermissionTo('manage_expenses')
            && $user->tenant_id === $expenseCategory->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_expenses');
    }

    public function update(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->hasPermissionTo('manage_expenses')
            && $user->tenant_id === $expenseCategory->tenant_id;
    }

    public function delete(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->hasPermissionTo('manage_expenses')
            && $user->tenant_id === $expenseCategory->tenant_id;
    }
}
