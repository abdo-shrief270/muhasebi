<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_payments');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->hasPermissionTo('manage_payments');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_payments');
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->hasPermissionTo('manage_payments')
            && $user->tenant_id === $payment->tenant_id;
    }
}
