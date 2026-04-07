<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Observers;

use App\Domain\Accounting\Models\Account;
use Illuminate\Support\Facades\Cache;

class AccountObserver
{
    public function saved(Account $account): void
    {
        Cache::forget("account_hierarchy:{$account->tenant_id}");
    }

    public function deleted(Account $account): void
    {
        Cache::forget("account_hierarchy:{$account->tenant_id}");
    }
}
