<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\FixedAsset\Models\AssetCategory;
use App\Models\User;

class AssetCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets');
    }

    public function view(User $user, AssetCategory $assetCategory): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets')
            && $user->tenant_id === $assetCategory->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets');
    }

    public function update(User $user, AssetCategory $assetCategory): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets')
            && $user->tenant_id === $assetCategory->tenant_id;
    }

    public function delete(User $user, AssetCategory $assetCategory): bool
    {
        return $user->hasPermissionTo('manage_fixed_assets')
            && $user->tenant_id === $assetCategory->tenant_id;
    }
}
