<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage_invoices');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('manage_invoices')
            && $user->tenant_id === $invoice->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage_invoices');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('manage_invoices')
            && $user->tenant_id === $invoice->tenant_id;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('manage_invoices')
            && $user->tenant_id === $invoice->tenant_id;
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('send_invoices')
            && $user->tenant_id === $invoice->tenant_id;
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('manage_invoices')
            && $user->tenant_id === $invoice->tenant_id;
    }

    public function postToGL(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('manage_invoices')
            && $user->tenant_id === $invoice->tenant_id;
    }

    public function creditNote(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionTo('manage_invoices')
            && $user->tenant_id === $invoice->tenant_id;
    }
}
