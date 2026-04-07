<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Collection\Models\CollectionAction;
use App\Models\User;

class CollectionActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_collections');
    }

    public function view(User $user, CollectionAction $collectionAction): bool
    {
        return $user->hasPermissionTo('manage_collections')
            && $user->tenant_id === $collectionAction->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_collections');
    }

    public function update(User $user, CollectionAction $collectionAction): bool
    {
        return $user->hasPermissionTo('manage_collections')
            && $user->tenant_id === $collectionAction->tenant_id;
    }

    public function delete(User $user, CollectionAction $collectionAction): bool
    {
        return $user->hasPermissionTo('manage_collections')
            && $user->tenant_id === $collectionAction->tenant_id;
    }
}
