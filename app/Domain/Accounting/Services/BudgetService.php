<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\BudgetStatus;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Budget;
use App\Domain\Accounting\Models\BudgetLine;
use App\Domain\Accounting\Models\FiscalYear;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetService
{
    /**
     * List budgets.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Budget::query()
            ->with('fiscalYear:id,name,start_date,end_date')
            ->when(isset($filters['fiscal_year_id']), fn ($q) => $q->where('fiscal_year_id', $filters['fiscal_year_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->withCount('lines')
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new budget.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Budget
    {
        $fiscalYear = FiscalYear::findOrFail($data['fiscal_year_id']);

        return Budget::create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Set budget lines (upsert).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function setLines(Budget $budget, array $lines): Budget
    {
        if (! $budget->isDraft()) {
            throw ValidationException::withMessages([
                'budget' => ['Only draft budgets can be modified.'],
            ]);
        }

        foreach ($lines as $line) {
            $budgetLine = BudgetLine::updateOrCreate(
                ['budget_id' => $budget->id, 'account_id' => $line['account_id']],
                array_filter([
                    'annual_amount' => $line['annual_amount'] ?? null,
                    'm1' => $line['m1'] ?? null, 'm2' => $line['m2'] ?? null,
                    'm3' => $line['m3'] ?? null, 'm4' => $line['m4'] ?? null,
                    'm5' => $line['m5'] ?? null, 'm6' => $line['m6'] ?? null,
                    'm7' => $line['m7'] ?? null, 'm8' => $line['m8'] ?? null,
                    'm9' => $line['m9'] ?? null, 'm10' => $line['m10'] ?? null,
                    'm11' => $line['m11'] ?? null, 'm12' => $line['m12'] ?? null,
                ], fn ($v) => $v !== null),
            );

            // Auto-distribute if only annual_amount is set and monthly are all zero
            if (isset($line['distribute']) && $line['distribute']) {
                $budgetLine->distributeEvenly();
                $budgetLine->save();
            }
        }

        return $budget->refresh()->load('lines.account:id,code,name_ar,name_en,type,normal_balance');
    }

    /**
     * Approve a budget.
     */
    public function approve(Budget $budget): Budget
    {
        if (! $budget->isDraft()) {
            throw ValidationException::withMessages([
                'budget' => ['Only draft budgets can be approved.'],
            ]);
        }

        $budget->update([
            'status' => BudgetStatus::Approved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return $budget->refresh();
    }

    /**
     * Generate budget vs actuals variance report.
     *
     * @return array<string, mixed>
     */
    public function variance(Budget $budget, ?int $throughMonth = null): array
    {
        $budget->load(['fiscalYear', 'lines.account']);
        $fiscalYear = $budget->fiscalYear;
        $tenantId = (int) app('tenant.id');

        // Default to current month within the fiscal year
        if ($throughMonth === null) {
            $today = now();
            if ($today->between($fiscalYear->start_date, $fiscalYear->end_date)) {
                $throughMonth = (int) $fiscalYear->start_date->diffInMonths($today) + 1;
            } else {
                $throughMonth = 12;
            }
            $throughMonth = min(12, max(1, $throughMonth));
        }

        // Calculate actual amounts from GL for each budgeted account
        $accountIds = $budget->lines->pluck('account_id')->toArray();

        // Get actuals per month for the fiscal year
        $yearStart = $fiscalYear->start_date;

        // Bulk query: get all actuals grouped by account_id and month in a SINGLE query
        $periodEnd = $yearStart->copy()->addMonths($throughMonth)->subDay();
        $bulkActuals = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->whereBetween('journal_entries.date', [$yearStart->toDateString(), $periodEnd->toDateString()])
            ->select(
                'journal_entry_lines.account_id',
                DB::raw('EXTRACT(MONTH FROM journal_entries.date) as month_num'),
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit'),
            )
            ->groupBy('journal_entry_lines.account_id', DB::raw('EXTRACT(MONTH FROM journal_entries.date)'))
            ->get();

        // Index bulk results: actuals[account_id][month_num] = {total_debit, total_credit}
        $actualsIndex = [];
        foreach ($bulkActuals as $row) {
            $actualsIndex[$row->account_id][(int) $row->month_num] = $row;
        }

        $rows = [];
        $totalBudget = '0.00';
        $totalActual = '0.00';

        foreach ($budget->lines as $line) {
            $account = $line->account;
            $isDebitNormal = $account->normal_balance === NormalBalance::Debit;

            // Budget YTD (sum m1..throughMonth)
            $budgetYtd = $line->amountForRange(1, $throughMonth);

            // Actual YTD from bulk data
            $actualYtd = '0.00';

            // Monthly breakdown
            $monthlyData = [];
            for ($m = 1; $m <= $throughMonth; $m++) {
                $monthStart = $yearStart->copy()->addMonths($m - 1);
                // Determine the calendar month number for this fiscal month
                $calendarMonth = (int) $monthStart->format('n');

                $monthBudget = $line->amountForMonth($m);

                // Look up bulk actuals for this account and calendar month
                $monthActual = '0.00';
                if (isset($actualsIndex[$account->id][$calendarMonth])) {
                    $r = $actualsIndex[$account->id][$calendarMonth];
                    $debit = (string) $r->total_debit;
                    $credit = (string) $r->total_credit;
                    $monthActual = $isDebitNormal
                        ? bcsub($debit, $credit, 2)
                        : bcsub($credit, $debit, 2);
                }

                $actualYtd = bcadd($actualYtd, $monthActual, 2);

                $monthVariance = bcsub($monthBudget, $monthActual, 2);

                $monthlyData[] = [
                    'month' => $m,
                    'budget' => $monthBudget,
                    'actual' => $monthActual,
                    'variance' => $monthVariance,
                    'variance_pct' => bccomp($monthBudget, '0', 2) !== 0
                        ? number_format((float) bcmul(bcdiv($monthVariance, $monthBudget, 4), '100', 1), 1, '.', '')
                        : '0.0',
                ];
            }

            $varianceYtd = bcsub($budgetYtd, $actualYtd, 2);

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name_ar' => $account->name_ar,
                'name_en' => $account->name_en,
                'type' => $account->type->value,
                'annual_budget' => (string) $line->annual_amount,
                'budget_ytd' => $budgetYtd,
                'actual_ytd' => $actualYtd,
                'variance_ytd' => $varianceYtd,
                'variance_pct' => bccomp($budgetYtd, '0', 2) !== 0
                    ? number_format((float) bcmul(bcdiv($varianceYtd, $budgetYtd, 4), '100', 1), 1, '.', '')
                    : '0.0',
                'utilization_pct' => bccomp($budgetYtd, '0', 2) !== 0
                    ? number_format((float) bcmul(bcdiv($actualYtd, $budgetYtd, 4), '100', 1), 1, '.', '')
                    : '0.0',
                'monthly' => $monthlyData,
            ];

            $totalBudget = bcadd($totalBudget, $budgetYtd, 2);
            $totalActual = bcadd($totalActual, $actualYtd, 2);
        }

        $totalVariance = bcsub($totalBudget, $totalActual, 2);

        return [
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'name_ar' => $budget->name_ar,
                'status' => $budget->status,
                'fiscal_year' => $fiscalYear->name,
            ],
            'through_month' => $throughMonth,
            'rows' => $rows,
            'totals' => [
                'budget_ytd' => $totalBudget,
                'actual_ytd' => $totalActual,
                'variance_ytd' => $totalVariance,
                'variance_pct' => bccomp($totalBudget, '0', 2) !== 0
                    ? number_format((float) bcmul(bcdiv($totalVariance, $totalBudget, 4), '100', 1), 1, '.', '')
                    : '0.0',
            ],
            'generated_at' => now()->format('Y-m-d H:i'),
        ];
    }

    /**
     * Get actual amount for an account in a date range.
     */
    private function getAccountActual(int $tenantId, int $accountId, string $fromDate, string $toDate, bool $isDebitNormal): string
    {
        $result = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entry_lines.account_id', $accountId)
            ->whereBetween('journal_entries.date', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            ->first();

        $debit = (string) ($result->total_debit ?? '0');
        $credit = (string) ($result->total_credit ?? '0');

        return $isDebitNormal
            ? bcsub($debit, $credit, 2)
            : bcsub($credit, $debit, 2);
    }
}
