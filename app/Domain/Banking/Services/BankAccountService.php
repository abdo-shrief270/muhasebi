<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Models\BankAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class BankAccountService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        // Whitelist sortable columns — same defense pattern as Vendor/Bill
        // services. Default to created desc so newly added accounts show up
        // first; the list page can still ask for alphabetical via sort_by.
        $allowedSorts = ['account_name', 'bank_name', 'currency', 'created_at'];
        $sortBy = in_array($filters['sort_by'] ?? null, $allowedSorts, true)
            ? $filters['sort_by']
            : 'created_at';
        $sortDir = (($filters['sort_dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

        return BankAccount::query()
            ->with('glAccount:id,code,name_ar,name_en')
            ->when(isset($filters['search']), fn ($q) => $q->search($filters['search']))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['currency']), fn ($q) => $q->where('currency', strtoupper($filters['currency'])))
            ->orderBy($sortBy, $sortDir)
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): BankAccount
    {
        return BankAccount::query()
            ->create($data + ['tenant_id' => app('tenant.id')])
            ->refresh()
            ->load('glAccount:id,code,name_ar,name_en');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BankAccount $bankAccount, array $data): BankAccount
    {
        $bankAccount->fill($data)->save();

        return $bankAccount->load('glAccount:id,code,name_ar,name_en');
    }

    /**
     * Soft-delete a bank account. Reject when there are any payments or
     * reimbursements that still reference it — losing the link breaks the
     * audit trail.
     *
     * Note: when payments-from-bank features are wired, this guard should
     * also check those tables. Placeholder for now to keep the function
     * present and the contract clear.
     *
     * @throws ValidationException
     */
    public function delete(BankAccount $bankAccount): void
    {
        $bankAccount->delete();
    }
}
