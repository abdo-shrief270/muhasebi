<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\FixedAsset\Models\FixedAsset;
use App\Models\User;

class FixedAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets');
    }

    public function view(User $user, FixedAsset $fixedAsset): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets')
            && $user->tenant_id === $fixedAsset->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets');
    }

    public function update(User $user, FixedAsset $fixedAsset): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets')
            && $user->tenant_id === $fixedAsset->tenant_id;
    }

    public function delete(User $user, FixedAsset $fixedAsset): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets')
            && $user->tenant_id === $fixedAsset->tenant_id;
    }
}
