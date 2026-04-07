<?php

declare(strict_types=1);

namespace App\Domain\CostCenter\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\CostCenter\Models\CostCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CostCenterService
{
    /**
     * List cost centers with children count, filter by type/is_active/search.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return CostCenter::query()
            ->withCount('children')
            ->search($filters['search'] ?? null)
            ->when(isset($filters['type']), fn ($q) => $q->ofType(\App\Domain\CostCenter\Enums\CostCenterType::from($filters['type'])))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy($filters['sort_by'] ?? 'code', $filters['sort_dir'] ?? 'asc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a cost center. Validate parent exists if provided.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): CostCenter
    {
        if (isset($data['parent_id'])) {
            $parent = CostCenter::query()->find($data['parent_id']);

            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['The selected parent cost center does not exist.'],
                ]);
            }
        }

        return CostCenter::query()->create($data);
    }

    /**
     * Update a cost center. Prevent setting parent to self or descendant (circular reference check).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(CostCenter $costCenter, array $data): CostCenter
    {
        if (isset($data['parent_id'])) {
            // Cannot set parent to self
            if ((int) $data['parent_id'] === $costCenter->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A cost center cannot be its own parent.'],
                ]);
            }

            // Cannot set parent to a descendant (circular reference)
            $descendantIds = $costCenter->getDescendantIds();

            if (in_array((int) $data['parent_id'], $descendantIds, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Cannot set parent to a descendant cost center (circular reference).'],
                ]);
            }

            // Validate parent exists
            $parent = CostCenter::query()->find($data['parent_id']);

            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['The selected parent cost center does not exist.'],
                ]);
            }
        }

        $costCenter->update($data);

        return $costCenter->refresh();
    }

    /**
     * Delete a cost center. Only if no journal entry lines reference it and no children.
     *
     * @throws ValidationException
     */
    public function delete(CostCenter $costCenter): void
    {
        // Check for children
        if ($costCenter->children()->exists()) {
            throw ValidationException::withMessages([
                'cost_center' => ['Cannot delete a cost center that has children. Remove children first.'],
            ]);
        }

        // Check for journal entry lines referencing this cost center code
        $hasLines = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entry_lines.cost_center', $costCenter->code)
            ->exists();

        if ($hasLines) {
            throw ValidationException::withMessages([
                'cost_center' => ['Cannot delete a cost center that is referenced by journal entry lines.'],
            ]);
        }

        $costCenter->delete();
    }

    /**
     * P&L report for a specific cost center.
     * Query journal_entry_lines where cost_center = code, join with accounts,
     * group by account type (revenue vs expense). All bcmath.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(string $costCenter, ?string $from = null, ?string $to = null): array
    {
        $cc = CostCenter::query()->where('code', $costCenter)->first();

        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entry_lines.cost_center', $costCenter);

        if (app('tenant.id')) {
            $query->where('journal_entries.tenant_id', app('tenant.id'));
        }

        if ($from) {
            $query->where('journal_entries.date', '>=', $from);
        }

        if ($to) {
            $query->where('journal_entries.date', '<=', $to);
        }

        $rows = $query
            ->select(
                'accounts.id as account_id',
                'accounts.code as account_code',
                'accounts.name_ar as account_name_ar',
                'accounts.name_en as account_name_en',
                'accounts.type as account_type',
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit'),
            )
            ->groupBy(
                'accounts.id',
                'accounts.code',
                'accounts.name_ar',
                'accounts.name_en',
                'accounts.type',
            )
            ->get();

        $revenue = [];
        $expenses = [];
        $totalRevenue = '0.00';
        $totalExpenses = '0.00';

        foreach ($rows as $row) {
            // Revenue: normal balance is credit, so balance = credit - debit
            // Expense: normal balance is debit, so balance = debit - credit
            if ($row->account_type === AccountType::Revenue->value) {
                $amount = bcsub((string) $row->total_credit, (string) $row->total_debit, 2);
                $revenue[] = [
                    'account_id' => $row->account_id,
                    'account_code' => $row->account_code,
                    'account_name_ar' => $row->account_name_ar,
                    'account_name_en' => $row->account_name_en,
                    'amount' => $amount,
                ];
                $totalRevenue = bcadd($totalRevenue, $amount, 2);
            } elseif ($row->account_type === AccountType::Expense->value) {
                $amount = bcsub((string) $row->total_debit, (string) $row->total_credit, 2);
                $expenses[] = [
                    'account_id' => $row->account_id,
                    'account_code' => $row->account_code,
                    'account_name_ar' => $row->account_name_ar,
                    'account_name_en' => $row->account_name_en,
                    'amount' => $amount,
                ];
                $totalExpenses = bcadd($totalExpenses, $amount, 2);
            }
        }

        return [
            'cost_center' => $cc ? [
                'code' => $cc->code,
                'name_ar' => $cc->name_ar,
                'name_en' => $cc->name_en,
                'type' => $cc->type->value,
            ] : ['code' => $costCenter],
            'period' => ['from' => $from, 'to' => $to],
            'revenue' => $revenue,
            'total_revenue' => $totalRevenue,
            'expenses' => $expenses,
            'total_expenses' => $totalExpenses,
            'net_profit' => bcsub($totalRevenue, $totalExpenses, 2),
        ];
    }

    /**
     * Compare actual costs across cost centers.
     * Query journal_entry_lines grouped by cost_center, filter by date range.
     * Show totals per center with budget comparison if budget_amount is set.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function costAnalysis(array $filters = []): array
    {
        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('accounts.type', AccountType::Expense->value)
            ->whereNotNull('journal_entry_lines.cost_center');

        if (app('tenant.id')) {
            $query->where('journal_entries.tenant_id', app('tenant.id'));
        }

        if (isset($filters['from'])) {
            $query->where('journal_entries.date', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('journal_entries.date', '<=', $filters['to']);
        }

        $rows = $query
            ->select(
                'journal_entry_lines.cost_center',
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit'),
            )
            ->groupBy('journal_entry_lines.cost_center')
            ->get();

        // Load cost centers keyed by code for budget data
        $costCenters = CostCenter::query()
            ->whereIn('code', $rows->pluck('cost_center')->toArray())
            ->get()
            ->keyBy('code');

        $centers = [];
        $totalActual = '0.00';
        $totalBudget = '0.00';

        foreach ($rows as $row) {
            $actual = bcsub((string) $row->total_debit, (string) $row->total_credit, 2);
            $cc = $costCenters->get($row->cost_center);
            $budget = $cc && $cc->budget_amount ? (string) $cc->budget_amount : '0.00';
            $variance = bcsub($budget, $actual, 2);

            $utilizationPct = '0.00';
            if (bccomp($budget, '0', 2) > 0) {
                $utilizationPct = bcmul(bcdiv($actual, $budget, 4), '100', 2);
            }

            $centers[] = [
                'code' => $row->cost_center,
                'name' => $cc ? ($cc->name_en ?? $cc->name_ar) : $row->cost_center,
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $variance,
                'utilization_pct' => $utilizationPct,
            ];

            $totalActual = bcadd($totalActual, $actual, 2);
            $totalBudget = bcadd($totalBudget, $budget, 2);
        }

        return [
            'centers' => $centers,
            'totals' => [
                'actual' => $totalActual,
                'budget' => $totalBudget,
                'variance' => bcsub($totalBudget, $totalActual, 2),
                'utilization_pct' => bccomp($totalBudget, '0', 2) > 0
                    ? bcmul(bcdiv($totalActual, $totalBudget, 4), '100', 2)
                    : '0.00',
            ],
        ];
    }

    /**
     * Show how expenses are distributed across cost centers.
     * Group by account, then by cost center within each account.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function allocationReport(array $filters = []): array
    {
        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('accounts.type', AccountType::Expense->value)
            ->whereNotNull('journal_entry_lines.cost_center');

        if (app('tenant.id')) {
            $query->where('journal_entries.tenant_id', app('tenant.id'));
        }

        if (isset($filters['from'])) {
            $query->where('journal_entries.date', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('journal_entries.date', '<=', $filters['to']);
        }

        $rows = $query
            ->select(
                'accounts.id as account_id',
                'accounts.code as account_code',
                'accounts.name_ar as account_name_ar',
                'accounts.name_en as account_name_en',
                'journal_entry_lines.cost_center',
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit'),
            )
            ->groupBy(
                'accounts.id',
                'accounts.code',
                'accounts.name_ar',
                'accounts.name_en',
                'journal_entry_lines.cost_center',
            )
            ->orderBy('accounts.code')
            ->orderBy('journal_entry_lines.cost_center')
            ->get();

        // Load cost center names
        $costCenters = CostCenter::query()
            ->whereIn('code', $rows->pluck('cost_center')->unique()->toArray())
            ->get()
            ->keyBy('code');

        $accounts = [];
        $grandTotal = '0.00';

        foreach ($rows as $row) {
            $amount = bcsub((string) $row->total_debit, (string) $row->total_credit, 2);
            $cc = $costCenters->get($row->cost_center);

            $accountKey = $row->account_id;

            if (! isset($accounts[$accountKey])) {
                $accounts[$accountKey] = [
                    'account_id' => $row->account_id,
                    'account_code' => $row->account_code,
                    'account_name_ar' => $row->account_name_ar,
                    'account_name_en' => $row->account_name_en,
                    'total' => '0.00',
                    'centers' => [],
                ];
            }

            $accounts[$accountKey]['centers'][] = [
                'code' => $row->cost_center,
                'name' => $cc ? ($cc->name_en ?? $cc->name_ar) : $row->cost_center,
                'amount' => $amount,
            ];

            $accounts[$accountKey]['total'] = bcadd($accounts[$accountKey]['total'], $amount, 2);
            $grandTotal = bcadd($grandTotal, $amount, 2);
        }

        return [
            'accounts' => array_values($accounts),
            'grand_total' => $grandTotal,
        ];
    }
}
