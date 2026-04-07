<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseReportService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly ExpenseService $expenseService,
    ) {}

    /**
     * List expense reports with search, filter, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return ExpenseReport::query()
            ->with(['user', 'expenses.category'])
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
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new expense report and attach expenses.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ExpenseReport
    {
        return DB::transaction(function () use ($data): ExpenseReport {
            $report = ExpenseReport::query()->create([
                'user_id' => Auth::id(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => ExpenseStatus::Draft,
                'currency' => $data['currency'] ?? 'EGP',
                'notes' => $data['notes'] ?? null,
            ]);

            if (! empty($data['expense_ids'])) {
                $this->attachExpenses($report, $data['expense_ids']);
            }

            $this->recalculateTotals($report);

            return $report->load(['user', 'expenses.category']);
        });
    }

    /**
     * Add expenses to an existing report and recalculate totals.
     *
     * @param  array<int, int>  $expenseIds
     *
     * @throws ValidationException
     */
    public function addExpenses(ExpenseReport $report, array $expenseIds): ExpenseReport
    {
        if (! $report->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => ['Expenses can only be added to draft or rejected reports.'],
            ]);
        }

        return DB::transaction(function () use ($report, $expenseIds): ExpenseReport {
            $this->attachExpenses($report, $expenseIds);
            $this->recalculateTotals($report);

            return $report->refresh()->load(['user', 'expenses.category']);
        });
    }

    /**
     * Submit the report and all linked expenses.
     *
     * @throws ValidationException
     */
    public function submit(ExpenseReport $report): ExpenseReport
    {
        if (! $report->status->canSubmit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or rejected reports can be submitted.'],
            ]);
        }

        return DB::transaction(function () use ($report): ExpenseReport {
            $report->load('expenses');

            foreach ($report->expenses as $expense) {
                if ($expense->status->canSubmit()) {
                    $this->expenseService->submit($expense);
                }
            }

            $report->update([
                'status' => ExpenseStatus::Submitted,
            ]);

            return $report->refresh()->load(['user', 'expenses.category']);
        });
    }

    /**
     * Approve the report and all linked expenses. Posts GL entries.
     *
     * @throws ValidationException
     */
    public function approve(ExpenseReport $report): ExpenseReport
    {
        if (! $report->status->canApprove()) {
            throw ValidationException::withMessages([
                'status' => ['Only submitted reports can be approved.'],
            ]);
        }

        return DB::transaction(function () use ($report): ExpenseReport {
            $report->load('expenses');

            foreach ($report->expenses as $expense) {
                if ($expense->status->canApprove()) {
                    $this->expenseService->approve($expense);
                }
            }

            $report->update([
                'status' => ExpenseStatus::Approved,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            return $report->refresh()->load(['user', 'expenses.category']);
        });
    }

    /**
     * Reject the report and all linked expenses.
     *
     * @throws ValidationException
     */
    public function reject(ExpenseReport $report, ?string $reason = null): ExpenseReport
    {
        if (! $report->status->canReject()) {
            throw ValidationException::withMessages([
                'status' => ['Only submitted reports can be rejected.'],
            ]);
        }

        return DB::transaction(function () use ($report, $reason): ExpenseReport {
            $report->load('expenses');

            foreach ($report->expenses as $expense) {
                if ($expense->status->canReject()) {
                    $this->expenseService->reject($expense, $reason);
                }
            }

            $notes = $report->notes;

            if ($reason) {
                $notes = $notes
                    ? $notes."\nسبب الرفض: {$reason}"
                    : "سبب الرفض: {$reason}";
            }

            $report->update([
                'status' => ExpenseStatus::Rejected,
                'notes' => $notes,
            ]);

            return $report->refresh()->load(['user', 'expenses.category']);
        });
    }

    /**
     * Recalculate report totals from linked expenses using bcmath.
     */
    private function recalculateTotals(ExpenseReport $report): void
    {
        $report->load('expenses');

        $totalAmount = '0.00';
        $totalVat = '0.00';
        $grandTotal = '0.00';

        foreach ($report->expenses as $expense) {
            $totalAmount = bcadd($totalAmount, (string) $expense->amount, 2);
            $totalVat = bcadd($totalVat, (string) $expense->vat_amount, 2);
            $grandTotal = bcadd($grandTotal, (string) $expense->total, 2);
        }

        $report->update([
            'total_amount' => $totalAmount,
            'total_vat' => $totalVat,
            'grand_total' => $grandTotal,
        ]);
    }

    /**
     * Attach expenses to a report by updating their expense_report_id.
     *
     * @param  array<int, int>  $expenseIds
     */
    private function attachExpenses(ExpenseReport $report, array $expenseIds): void
    {
        Expense::query()
            ->whereIn('id', $expenseIds)
            ->whereNull('expense_report_id')
            ->update(['expense_report_id' => $report->id]);
    }
}
