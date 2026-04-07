<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\FiscalYear;
use App\Models\User;

class FiscalYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('post_journal_entries');
    }

    public function view(User $user, FiscalYear $fiscalYear): bool
    {
        return $user->hasPermissionTo('post_journal_entries')
            && $user->tenant_id === $fiscalYear->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('post_journal_entries');
    }
}
