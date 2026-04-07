<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class AccountService
{
    /**
     * List accounts with search, filter, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Account::query()
            ->search($filters['search'] ?? null)
            ->when(isset($filters['type']), fn ($q) => $q->ofType(AccountType::from($filters['type'])))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->when(isset($filters['parent_id']), fn ($q) => $q->where('parent_id', $filters['parent_id']))
            ->when(isset($filters['is_group']), fn ($q) => $q->where('is_group', $filters['is_group']))
            ->orderBy($filters['sort_by'] ?? 'code', $filters['sort_dir'] ?? 'asc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get root accounts with recursively eager-loaded children, ordered by code.
     */
    public function getTree(): Collection
    {
        return Account::query()
            ->roots()
            ->with('children')
            ->orderBy('code')
            ->get();
    }

    /**
     * Create a new account.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        if (isset($data['parent_id'])) {
            $parent = Account::query()->findOrFail($data['parent_id']);
            $data['level'] = $parent->level + 1;
        }

        return Account::query()->create($data);
    }

    /**
     * Update an existing account.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Account $account, array $data): Account
    {
        if (
            isset($data['type'])
            && $data['type'] !== $account->type->value
            && $account->journalEntryLines()
                ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalEntryStatus::Posted))
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'type' => ['Cannot change account type because posted journal entries exist for this account.'],
            ]);
        }

        if (isset($data['parent_id']) && $data['parent_id'] !== $account->parent_id) {
            $parent = Account::query()->findOrFail($data['parent_id']);
            $data['level'] = $parent->level + 1;
        }

        $account->update($data);

        return $account->refresh();
    }

    /**
     * Soft-delete an account.
     *
     * @throws ValidationException
     */
    public function delete(Account $account): void
    {
        if ($account->journalEntryLines()->exists()) {
            throw ValidationException::withMessages([
                'account' => ['Cannot delete account because it has journal entry lines.'],
            ]);
        }

        $account->delete();
    }

    /**
     * Show an account with its parent and children loaded.
     */
    public function show(Account $account): Account
    {
        return $account->load(['parent', 'children']);
    }

    /**
     * Calculate account balance from posted journal entries.
     *
     * @return array{debit: string, credit: string, balance: string}
     */
    public function getBalance(Account $account, ?string $fromDate = null, ?string $toDate = null): array
    {
        $query = $account->journalEntryLines()
            ->whereHas('journalEntry', function ($q) use ($fromDate, $toDate): void {
                $q->where('status', JournalEntryStatus::Posted);

                if ($fromDate) {
                    $q->where('date', '>=', $fromDate);
                }

                if ($toDate) {
                    $q->where('date', '<=', $toDate);
                }
            });

        $totals = $query->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $totalDebit = (string) ($totals->total_debit ?? '0');
        $totalCredit = (string) ($totals->total_credit ?? '0');

        $debitAmount = bccomp($totalDebit, '0', 2) === 0 ? '0.00' : $totalDebit;
        $creditAmount = bccomp($totalCredit, '0', 2) === 0 ? '0.00' : $totalCredit;

        $balance = $account->normal_balance === NormalBalance::Debit
            ? bcsub($debitAmount, $creditAmount, 2)
            : bcsub($creditAmount, $debitAmount, 2);

        return [
            'debit' => $debitAmount,
            'credit' => $creditAmount,
            'balance' => $balance,
        ];
    }
}
