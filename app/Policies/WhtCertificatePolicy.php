<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tax\Models\WhtCertificate;
use App\Models\User;

class WhtCertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_tax');
    }

    public function view(User $user, WhtCertificate $whtCertificate): bool
    {
        return $user->hasPermissionTo('manage_tax')
            && $user->tenant_id === $whtCertificate->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_tax');
    }

    public function update(User $user, WhtCertificate $whtCertificate): bool
    {
        return $user->hasPermissionTo('manage_tax')
            && $user->tenant_id === $whtCertificate->tenant_id;
    }

    public function delete(User $user, WhtCertificate $whtCertificate): bool
    {
        return $user->hasPermissionTo('manage_tax')
            && $user->tenant_id === $whtCertificate->tenant_id;
    }
}
