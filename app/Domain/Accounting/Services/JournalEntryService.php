<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class JournalEntryService
{
    /**
     * List journal entries with search, filter, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->with(['lines.account'])
            ->when(
                isset($filters['search']),
                fn ($q) => $q->where(function ($q) use ($filters): void {
                    $term = mb_strtolower($filters['search']);
                    $q->whereRaw('LOWER(description) like ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(entry_number) like ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(reference) like ?', ["%{$term}%"]);
                })
            )
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']))
            ->when(isset($filters['fiscal_period_id']), fn ($q) => $q->where('fiscal_period_id', $filters['fiscal_period_id']))
            ->orderBy($filters['sort_by'] ?? 'date', $filters['sort_dir'] ?? 'desc')
            ->orderBy('entry_number', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new journal entry with lines inside a transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data): JournalEntry {
            $lines = $data['lines'] ?? [];

            $this->validateLines($lines);

            // Find fiscal period for the entry date
            $fiscalPeriodId = $data['fiscal_period_id'] ?? null;

            if (! $fiscalPeriodId) {
                $period = FiscalPeriod::query()
                    ->containingDate($data['date'])
                    ->first();

                if (! $period) {
                    throw ValidationException::withMessages([
                        'date' => ['No fiscal period found for the given date.'],
                    ]);
                }

                $fiscalPeriodId = $period->id;
            }

            // Validate fiscal period is open
            $fiscalPeriod = FiscalPeriod::query()->findOrFail($fiscalPeriodId);

            if ($fiscalPeriod->is_closed) {
                throw ValidationException::withMessages([
                    'fiscal_period_id' => ['The fiscal period is closed.'],
                ]);
            }

            $totalDebit = '0.00';
            $totalCredit = '0.00';
            foreach ($lines as $line) {
                $totalDebit = bcadd($totalDebit, (string) ($line['debit'] ?? '0'), 2);
                $totalCredit = bcadd($totalCredit, (string) ($line['credit'] ?? '0'), 2);
            }

            $tenantId = (int) app('tenant.id');

            $entry = JournalEntry::query()->create([
                'entry_number' => $this->generateEntryNumber($tenantId),
                'date' => $data['date'],
                'description' => $data['description'] ?? null,
                'reference' => $data['reference'] ?? null,
                'status' => JournalEntryStatus::Draft,
                'fiscal_period_id' => $fiscalPeriodId,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'created_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'currency' => $line['currency'] ?? 'EGP',
                    'description' => $line['description'] ?? null,
                    'cost_center' => $line['cost_center'] ?? null,
                ]);

                // Learn pattern for AI account suggestions
                if (! empty($line['description']) && ! empty($line['account_id'])) {
                    try {
                        app(AccountSuggestionService::class)->learn($line['description'], $line['account_id']);
                    } catch (\Throwable $e) {
                        Log::warning('Account suggestion learning failed', [
                            'description' => $line['description'] ?? '',
                            'account_id' => $line['account_id'] ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return $entry->load('lines.account');
        });
    }

    /**
     * Update a draft journal entry with new data and lines.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(JournalEntry $entry, array $data): JournalEntry
    {
        if (! $entry->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft journal entries can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($entry, $data): JournalEntry {
            $lines = $data['lines'] ?? [];

            $this->validateLines($lines);

            // Handle fiscal period change if date changed
            $fiscalPeriodId = $data['fiscal_period_id'] ?? $entry->fiscal_period_id;

            if (isset($data['date']) && $data['date'] !== $entry->date) {
                $period = FiscalPeriod::query()
                    ->containingDate($data['date'])
                    ->first();

                if (! $period) {
                    throw ValidationException::withMessages([
                        'date' => ['No fiscal period found for the given date.'],
                    ]);
                }

                $fiscalPeriodId = $period->id;
            }

            // Validate fiscal period is open
            $fiscalPeriod = FiscalPeriod::query()->findOrFail($fiscalPeriodId);

            if ($fiscalPeriod->is_closed) {
                throw ValidationException::withMessages([
                    'fiscal_period_id' => ['The fiscal period is closed.'],
                ]);
            }

            $totalDebit = '0.00';
            $totalCredit = '0.00';
            foreach ($lines as $line) {
                $totalDebit = bcadd($totalDebit, (string) ($line['debit'] ?? '0'), 2);
                $totalCredit = bcadd($totalCredit, (string) ($line['credit'] ?? '0'), 2);
            }

            $entry->update([
                'date' => $data['date'] ?? $entry->date,
                'description' => $data['description'] ?? $entry->description,
                'reference' => $data['reference'] ?? $entry->reference,
                'fiscal_period_id' => $fiscalPeriodId,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            // Delete existing lines and recreate
            $entry->lines()->delete();

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'description' => $line['description'] ?? null,
                    'cost_center' => $line['cost_center'] ?? null,
                ]);
            }

            return $entry->refresh()->load('lines.account');
        });
    }

    /**
     * Show a journal entry with all related data loaded.
     */
    public function show(JournalEntry $entry): JournalEntry
    {
        return $entry->load(['lines.account', 'fiscalPeriod', 'createdByUser', 'postedByUser']);
    }

    /**
     * Soft-delete a draft journal entry.
     *
     * @throws ValidationException
     */
    public function delete(JournalEntry $entry): void
    {
        if (! $entry->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft journal entries can be deleted.'],
            ]);
        }

        $entry->delete();
    }

    /**
     * Post a draft journal entry.
     *
     * @throws ValidationException
     */
    public function post(JournalEntry $entry): JournalEntry
    {
        if (! $entry->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft journal entries can be posted.'],
            ]);
        }

        return DB::transaction(function () use ($entry): JournalEntry {
            if (! $entry->isBalanced()) {
                throw ValidationException::withMessages([
                    'lines' => ['Journal entry is not balanced. Total debits must equal total credits.'],
                ]);
            }

            $entry->update([
                'status' => JournalEntryStatus::Posted,
                'posted_at' => now(),
                'posted_by' => Auth::id(),
            ]);

            return $entry->refresh();
        });
    }

    /**
     * Reverse a posted journal entry by creating a new reversing entry.
     *
     * @throws ValidationException
     */
    public function reverse(JournalEntry $entry): JournalEntry
    {
        if (! $entry->status->canReverse()) {
            throw ValidationException::withMessages([
                'status' => ['Only posted journal entries can be reversed.'],
            ]);
        }

        return DB::transaction(function () use ($entry): JournalEntry {
            $entry->load('lines');

            $tenantId = (int) app('tenant.id');

            // Create the reversing entry
            $reversalEntry = JournalEntry::query()->create([
                'entry_number' => $this->generateEntryNumber($tenantId),
                'date' => now()->toDateString(),
                'description' => "Reversal of {$entry->entry_number}: {$entry->description}",
                'reference' => $entry->reference,
                'status' => JournalEntryStatus::Posted,
                'fiscal_period_id' => $entry->fiscal_period_id,
                'total_debit' => $entry->total_credit,
                'total_credit' => $entry->total_debit,
                'reversal_of_id' => $entry->id,
                'posted_at' => now(),
                'posted_by' => Auth::id(),
                'created_by' => Auth::id(),
            ]);

            // Create reversed lines (swap debit and credit)
            foreach ($entry->lines as $line) {
                $reversalEntry->lines()->create([
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => $line->description,
                    'cost_center' => $line->cost_center,
                ]);
            }

            // Mark original as reversed
            $entry->update([
                'status' => JournalEntryStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => Auth::id(),
            ]);

            return $reversalEntry->load('lines.account');
        });
    }

    /**
     * Generate the next sequential entry number for a tenant.
     */
    public function generateEntryNumber(int $tenantId): string
    {
        $prefix = 'JE-';

        $maxNumber = JournalEntry::query()
            ->forTenant($tenantId)
            ->where('entry_number', 'like', $prefix.'%')
            ->selectRaw("MAX(CAST(SUBSTRING(entry_number FROM ?) AS INTEGER)) as max_num", [mb_strlen($prefix) + 1])
            ->value('max_num') ?? 0;

        $nextNumber = $maxNumber + 1;

        return $prefix.str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Validate journal entry lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    private function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => ['A journal entry must have at least 2 lines.'],
            ]);
        }

        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($lines as $index => $line) {
            $debit = (string) ($line['debit'] ?? '0');
            $credit = (string) ($line['credit'] ?? '0');

            if (bccomp($debit, '0', 2) > 0 && bccomp($credit, '0', 2) > 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => ['A line cannot have both debit and credit amounts.'],
                ]);
            }

            if (bccomp($debit, '0', 2) <= 0 && bccomp($credit, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => ['A line must have either a debit or credit amount greater than zero.'],
                ]);
            }

            $accountId = $line['account_id'] ?? null;
            $account = Account::query()->find($accountId);

            if (! $account) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_id" => ['The selected account does not exist.'],
                ]);
            }

            if ($account->is_group) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_id" => ['Cannot post to a group account. Select a leaf account.'],
                ]);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_id" => ['Cannot post to an inactive account.'],
                ]);
            }

            $totalDebit = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);
        }

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw ValidationException::withMessages([
                'lines' => ['Total debits must equal total credits. Debit: '.$totalDebit.', Credit: '.$totalCredit],
            ]);
        }
    }
}
