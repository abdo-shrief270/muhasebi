<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_journal_entries');
    }

    public function view(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('manage_journal_entries')
            && $user->tenant_id === $journalEntry->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_journal_entries');
    }

    public function update(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('manage_journal_entries')
            && $user->tenant_id === $journalEntry->tenant_id;
    }

    public function delete(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('manage_journal_entries')
            && $user->tenant_id === $journalEntry->tenant_id;
    }

    public function post(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('post_journal_entries')
            && $user->tenant_id === $journalEntry->tenant_id;
    }

    public function reverse(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('manage_journal_entries')
            && $user->tenant_id === $journalEntry->tenant_id;
    }
}
