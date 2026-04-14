<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Services\GLPostingService;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly GLPostingService $glPostingService,
    ) {}

    /**
     * List expenses with search, filter, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Expense::query()
            ->with(['category', 'user'])
            ->when(
                isset($filters['search']),
                fn ($q) => $q->search($filters['search'])
            )
            ->when(
                isset($filters['status']),
                fn ($q) => $q->ofStatus(
                    $filters['status'] instanceof ExpenseStatus
                        ? $filters['status']
                        : ExpenseStatus::from($filters['status'])
                )
            )
            ->when(isset($filters['user_id']), fn ($q) => $q->forUser((int) $filters['user_id']))
            ->when(isset($filters['category_id']), fn ($q) => $q->forCategory((int) $filters['category_id']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']))
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new expense.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Expense
    {
        return DB::transaction(function () use ($data): Expense {
            $amount = (string) ($data['amount'] ?? '0');
            $vatRate = (string) ($data['vat_rate'] ?? '0');
            $vatAmount = '0.00';

            if (bccomp($vatRate, '0', 2) > 0) {
                $vatAmount = bcdiv(bcmul($amount, $vatRate, 4), '100', 2);
            }

            $total = bcadd($amount, $vatAmount, 2);

            $receiptPath = null;

            if (isset($data['receipt'])) {
                $tenantId = (int) app('tenant.id');
                $receiptPath = $data['receipt']->store(
                    "expenses/{$tenantId}",
                    'private'
                );
            }

            $expense = Expense::query()->create([
                'user_id' => Auth::id(),
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? '',
                'amount' => $amount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'currency' => $data['currency'] ?? 'EGP',
                'date' => $data['date'] ?? now()->toDateString(),
                'receipt_path' => $receiptPath,
                'status' => ExpenseStatus::Draft,
                'notes' => $data['notes'] ?? null,
            ]);

            return $expense->load(['category', 'user']);
        });
    }

    /**
     * Update a draft or rejected expense.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Expense $expense, array $data): Expense
    {
        if (! $expense->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or rejected expenses can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($expense, $data): Expense {
            $amount = (string) ($data['amount'] ?? (string) $expense->amount);
            $vatRate = (string) ($data['vat_rate'] ?? (string) $expense->vat_rate);
            $vatAmount = '0.00';

            if (bccomp($vatRate, '0', 2) > 0) {
                $vatAmount = bcdiv(bcmul($amount, $vatRate, 4), '100', 2);
            }

            $total = bcadd($amount, $vatAmount, 2);

            $receiptPath = $expense->receipt_path;

            if (isset($data['receipt'])) {
                // Delete old receipt
                if ($receiptPath) {
                    Storage::disk('private')->delete($receiptPath);
                }

                $tenantId = (int) app('tenant.id');
                $receiptPath = $data['receipt']->store(
                    "expenses/{$tenantId}",
                    'private'
                );
            }

            $expense->update([
                'category_id' => $data['category_id'] ?? $expense->category_id,
                'description' => $data['description'] ?? $expense->description,
                'amount' => $amount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'currency' => $data['currency'] ?? $expense->currency,
                'date' => $data['date'] ?? $expense->date,
                'receipt_path' => $receiptPath,
                'notes' => $data['notes'] ?? $expense->notes,
            ]);

            return $expense->refresh()->load(['category', 'user']);
        });
    }

    /**
     * Delete a draft expense and its receipt file.
     *
     * @throws ValidationException
     */
    public function delete(Expense $expense): void
    {
        if (! $expense->status->canDelete()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft expenses can be deleted.'],
            ]);
        }

        if ($expense->receipt_path) {
            Storage::disk('private')->delete($expense->receipt_path);
        }

        $expense->delete();
    }

    /**
     * Submit an expense for approval.
     *
     * @throws ValidationException
     */
    public function submit(Expense $expense): Expense
    {
        if (! $expense->status->canSubmit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or rejected expenses can be submitted.'],
            ]);
        }

        $expense->update([
            'status' => ExpenseStatus::Submitted,
        ]);

        return $expense->refresh();
    }

    /**
     * Approve an expense and post to GL.
     * DEBIT expense account (from category), CREDIT AP / employee payable.
     *
     * @throws ValidationException
     */
    public function approve(Expense $expense): Expense
    {
        if (! $expense->status->canApprove()) {
            throw ValidationException::withMessages([
                'status' => ['Only submitted expenses can be approved.'],
            ]);
        }

        return DB::transaction(function () use ($expense): Expense {
            $expense->load('category.glAccount');

            $expense->update([
                'status' => ExpenseStatus::Approved,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            // Post to GL
            $tenantId = (int) app('tenant.id');
            $currency = $expense->currency ?? 'EGP';
            $total = (string) $expense->total;

            // Resolve expense account from category
            $expenseAccountId = $expense->category?->gl_account_id;

            if (! $expenseAccountId) {
                throw ValidationException::withMessages([
                    'category' => ['Expense category must have a GL account assigned for approval.'],
                ]);
            }

            // Resolve AP account
            $apAccountId = $this->glPostingService->resolveAccount(
                config('accounting.default_accounts.accounts_payable'),
                $tenantId
            );

            $jeLines = [];

            // DEBIT: Expense account
            $debitAmount = $total;
            $jeLines[] = [
                'account_id' => $expenseAccountId,
                'debit' => (float) $debitAmount,
                'credit' => 0,
                'currency' => $currency,
                'description' => "مصروف: {$expense->description}",
            ];

            // Handle VAT if present
            if (bccomp((string) $expense->vat_amount, '0', 2) > 0) {
                // Adjust: DEBIT expense for net amount, DEBIT VAT input for VAT
                $netAmount = (string) $expense->amount;
                $vatAmount = (string) $expense->vat_amount;

                $vatInputAccountId = $this->glPostingService->resolveAccount(
                    config('accounting.default_accounts.vat_input'),
                    $tenantId
                );

                // Replace the total debit with net amount
                $jeLines[0]['debit'] = (float) $netAmount;

                $jeLines[] = [
                    'account_id' => $vatInputAccountId,
                    'debit' => (float) $vatAmount,
                    'credit' => 0,
                    'currency' => $currency,
                    'description' => "ضريبة القيمة المضافة - مصروف: {$expense->description}",
                ];
            }

            // CREDIT: Accounts Payable
            $jeLines[] = [
                'account_id' => $apAccountId,
                'debit' => 0,
                'credit' => (float) $total,
                'currency' => $currency,
                'description' => "مصروف: {$expense->description}",
            ];

            $journalEntry = $this->glPostingService->post([
                'date' => $expense->date->toDateString(),
                'description' => "مصروف: {$expense->description}",
                'reference' => "EXP-{$expense->id}",
                'lines' => $jeLines,
            ]);

            $expense->update([
                'journal_entry_id' => $journalEntry->id,
            ]);

            return $expense->refresh()->load(['category', 'user', 'journalEntry']);
        });
    }

    /**
     * Reject an expense with an optional reason.
     *
     * @throws ValidationException
     */
    public function reject(Expense $expense, ?string $reason = null): Expense
    {
        if (! $expense->status->canReject()) {
            throw ValidationException::withMessages([
                'status' => ['Only submitted expenses can be rejected.'],
            ]);
        }

        $notes = $expense->notes;

        if ($reason) {
            $notes = $notes
                ? $notes."\nسبب الرفض: {$reason}"
                : "سبب الرفض: {$reason}";
        }

        $expense->update([
            'status' => ExpenseStatus::Rejected,
            'notes' => $notes,
        ]);

        return $expense->refresh();
    }

    /**
     * Mark an approved expense as reimbursed.
     * Optionally posts a payment GL entry (DEBIT AP, CREDIT cash/bank).
     *
     * @throws ValidationException
     */
    public function reimburse(Expense $expense): Expense
    {
        if (! $expense->status->canReimburse()) {
            throw ValidationException::withMessages([
                'status' => ['Only approved expenses can be reimbursed.'],
            ]);
        }

        return DB::transaction(function () use ($expense): Expense {
            $expense->update([
                'status' => ExpenseStatus::Reimbursed,
                'reimbursed_at' => now(),
            ]);

            // Post payment GL entry: DEBIT AP, CREDIT Bank
            $tenantId = (int) app('tenant.id');
            $currency = $expense->currency ?? 'EGP';
            $total = (string) $expense->total;

            $apAccountId = $this->glPostingService->resolveAccount(
                config('accounting.default_accounts.accounts_payable'),
                $tenantId
            );

            $bankAccountId = $this->glPostingService->resolveAccount(
                config('accounting.default_accounts.bank'),
                $tenantId
            );

            $this->glPostingService->post([
                'date' => now()->toDateString(),
                'description' => "سداد مصروف: {$expense->description}",
                'reference' => "EXP-PAY-{$expense->id}",
                'lines' => [
                    [
                        'account_id' => $apAccountId,
                        'debit' => (float) $total,
                        'credit' => 0,
                        'currency' => $currency,
                        'description' => "سداد مصروف: {$expense->description}",
                    ],
                    [
                        'account_id' => $bankAccountId,
                        'debit' => 0,
                        'credit' => (float) $total,
                        'currency' => $currency,
                        'description' => "سداد مصروف: {$expense->description}",
                    ],
                ],
            ]);

            return $expense->refresh();
        });
    }

    /**
     * Submit multiple expenses at once.
     *
     * @param  array<int, int>  $ids
     * @return array<int, Expense>
     *
     * @throws ValidationException
     */
    public function bulkSubmit(array $ids): array
    {
        return DB::transaction(function () use ($ids): array {
            $expenses = Expense::query()->whereIn('id', $ids)->get();
            $submitted = [];

            foreach ($expenses as $expense) {
                if ($expense->status->canSubmit()) {
                    $expense->update([
                        'status' => ExpenseStatus::Submitted,
                    ]);
                    $submitted[] = $expense->refresh();
                }
            }

            return $submitted;
        });
    }

    /**
     * Expense summary: by category, by user, by month. All bcmath.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $query = Expense::query()
            ->when(isset($filters['status']), fn ($q) => $q->ofStatus(
                $filters['status'] instanceof ExpenseStatus
                    ? $filters['status']
                    : ExpenseStatus::from($filters['status'])
            ))
            ->when(isset($filters['user_id']), fn ($q) => $q->forUser((int) $filters['user_id']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']));

        $expenses = $query->with(['category', 'user'])->get();

        // By category
        $byCategory = [];
        foreach ($expenses as $expense) {
            $categoryName = $expense->category?->name ?? 'Uncategorized';
            $categoryId = $expense->category_id ?? 0;

            if (! isset($byCategory[$categoryId])) {
                $byCategory[$categoryId] = [
                    'category_id' => $categoryId,
                    'category_name' => $categoryName,
                    'total' => '0.00',
                    'count' => 0,
                ];
            }

            $byCategory[$categoryId]['total'] = bcadd($byCategory[$categoryId]['total'], (string) $expense->total, 2);
            $byCategory[$categoryId]['count']++;
        }

        // By user
        $byUser = [];
        foreach ($expenses as $expense) {
            $userId = $expense->user_id;
            $userName = $expense->user?->name ?? 'Unknown';

            if (! isset($byUser[$userId])) {
                $byUser[$userId] = [
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'total' => '0.00',
                    'count' => 0,
                ];
            }

            $byUser[$userId]['total'] = bcadd($byUser[$userId]['total'], (string) $expense->total, 2);
            $byUser[$userId]['count']++;
        }

        // By month
        $byMonth = [];
        foreach ($expenses as $expense) {
            $monthKey = $expense->date->format('Y-m');

            if (! isset($byMonth[$monthKey])) {
                $byMonth[$monthKey] = [
                    'month' => $monthKey,
                    'total' => '0.00',
                    'count' => 0,
                ];
            }

            $byMonth[$monthKey]['total'] = bcadd($byMonth[$monthKey]['total'], (string) $expense->total, 2);
            $byMonth[$monthKey]['count']++;
        }

        // Grand total
        $grandTotal = '0.00';
        foreach ($expenses as $expense) {
            $grandTotal = bcadd($grandTotal, (string) $expense->total, 2);
        }

        return [
            'grand_total' => $grandTotal,
            'count' => $expenses->count(),
            'by_category' => array_values($byCategory),
            'by_user' => array_values($byUser),
            'by_month' => array_values($byMonth),
        ];
    }

}
