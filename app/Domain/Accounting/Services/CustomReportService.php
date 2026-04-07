<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\SavedReport;
use Illuminate\Support\Facades\DB;

class CustomReportService
{
    /**
     * Execute a custom report from a configuration array.
     *
     * Config structure:
     * {
     *   "accounts": {
     *     "types": ["asset", "expense"],       // filter by account type
     *     "codes_from": "1000",                // filter by code range
     *     "codes_to": "1999",
     *     "ids": [1, 2, 3]                     // filter by specific IDs
     *   },
     *   "date_range": {"from": "2026-01-01", "to": "2026-03-31"},
     *   "columns": ["code", "name", "opening_balance", "debit", "credit", "closing_balance", "net_change"],
     *   "grouping": "parent",                  // parent, type, flat
     *   "include_zero_balances": false,
     *   "comparison": {
     *     "enabled": true,
     *     "prior_from": "2025-01-01",
     *     "prior_to": "2025-03-31"
     *   }
     * }
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(array $config): array
    {
        $accounts = $this->resolveAccounts($config['accounts'] ?? []);
        $dateRange = $config['date_range'] ?? [];
        $fromDate = $dateRange['from'] ?? null;
        $toDate = $dateRange['to'] ?? null;
        $columns = $config['columns'] ?? ['code', 'name', 'opening_balance', 'debit', 'credit', 'closing_balance'];
        $grouping = $config['grouping'] ?? 'flat';
        $includeZero = $config['include_zero_balances'] ?? false;

        // Get balances for current period
        $currentData = $this->buildReportData($accounts, $fromDate, $toDate, $columns, $grouping, $includeZero);

        $result = [
            'current' => $currentData,
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'config' => $config,
            'generated_at' => now()->format('Y-m-d H:i'),
        ];

        // Comparison period
        $comparison = $config['comparison'] ?? [];
        if (! empty($comparison['enabled'])) {
            $priorFrom = $comparison['prior_from'] ?? null;
            $priorTo = $comparison['prior_to'] ?? null;

            $priorData = $this->buildReportData($accounts, $priorFrom, $priorTo, $columns, $grouping, $includeZero);
            $result['prior'] = $priorData;
            $result['prior_period'] = ['from' => $priorFrom, 'to' => $priorTo];
            $result['variance'] = $this->calculateVariance($currentData, $priorData);
        }

        return $result;
    }

    /**
     * List saved report templates visible to the current user.
     */
    public function listSaved(int $userId, int $perPage = 15): mixed
    {
        return SavedReport::query()
            ->visibleTo($userId)
            ->with('creator:id,name')
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * Save a report template.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): SavedReport
    {
        return SavedReport::create([
            'created_by' => auth()->id(),
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'description' => $data['description'] ?? null,
            'config' => $data['config'],
            'is_shared' => $data['is_shared'] ?? false,
        ]);
    }

    /**
     * Update a saved report.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SavedReport $report, array $data): SavedReport
    {
        $report->update($data);

        return $report->refresh();
    }

    /**
     * Resolve which accounts to include based on config filters.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function resolveAccounts(array $filters): \Illuminate\Database\Eloquent\Collection
    {
        $query = Account::query()->active()->leafAccounts()->orderBy('code');

        // Filter by specific IDs
        if (! empty($filters['ids'])) {
            return $query->whereIn('id', $filters['ids'])->get();
        }

        // Filter by account types
        if (! empty($filters['types'])) {
            $types = array_map(fn ($t) => AccountType::from($t), $filters['types']);
            $query->whereIn('type', $types);
        }

        // Filter by code range
        if (! empty($filters['codes_from'])) {
            $query->where('code', '>=', $filters['codes_from']);
        }

        if (! empty($filters['codes_to'])) {
            $query->where('code', '<=', $filters['codes_to']);
        }

        return $query->get();
    }

    /**
     * Build report data for a set of accounts and date range.
     *
     * @return array<string, mixed>
     */
    private function buildReportData(
        \Illuminate\Database\Eloquent\Collection $accounts,
        ?string $fromDate,
        ?string $toDate,
        array $columns,
        string $grouping,
        bool $includeZero,
    ): array {
        $tenantId = (int) app('tenant.id');
        $accountIds = $accounts->pluck('id')->toArray();

        if (empty($accountIds)) {
            return ['rows' => [], 'totals' => [], 'count' => 0];
        }

        // Opening balances (entries before fromDate)
        $openingBalances = collect();
        if ($fromDate) {
            $openingBalances = $this->queryBalances($tenantId, $accountIds, null, $fromDate);
        }

        // Period movements
        $periodMovements = $this->queryBalances($tenantId, $accountIds, $fromDate, $toDate);

        // Build rows
        $rows = [];
        $totals = [
            'opening_balance' => '0.00',
            'debit' => '0.00',
            'credit' => '0.00',
            'closing_balance' => '0.00',
            'net_change' => '0.00',
        ];

        foreach ($accounts as $account) {
            $opening = $openingBalances->get($account->id);
            $openingDebit = (string) ($opening->total_debit ?? '0');
            $openingCredit = (string) ($opening->total_credit ?? '0');

            $period = $periodMovements->get($account->id);
            $periodDebit = (string) ($period->total_debit ?? '0');
            $periodCredit = (string) ($period->total_credit ?? '0');

            $isDebitNormal = $account->normal_balance === NormalBalance::Debit;

            $openingBalance = $isDebitNormal
                ? bcsub($openingDebit, $openingCredit, 2)
                : bcsub($openingCredit, $openingDebit, 2);

            $netChange = $isDebitNormal
                ? bcsub($periodDebit, $periodCredit, 2)
                : bcsub($periodCredit, $periodDebit, 2);

            $closingBalance = bcadd($openingBalance, $netChange, 2);

            if (! $includeZero && bccomp($closingBalance, '0', 2) === 0 && bccomp($netChange, '0', 2) === 0) {
                continue;
            }

            $row = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name_ar' => $account->name_ar,
                'name_en' => $account->name_en,
                'type' => $account->type->value,
                'parent_id' => $account->parent_id,
                'opening_balance' => $openingBalance,
                'debit' => $periodDebit,
                'credit' => $periodCredit,
                'net_change' => $netChange,
                'closing_balance' => $closingBalance,
            ];

            $rows[] = $row;

            $totals['opening_balance'] = bcadd($totals['opening_balance'], $openingBalance, 2);
            $totals['debit'] = bcadd($totals['debit'], $periodDebit, 2);
            $totals['credit'] = bcadd($totals['credit'], $periodCredit, 2);
            $totals['net_change'] = bcadd($totals['net_change'], $netChange, 2);
            $totals['closing_balance'] = bcadd($totals['closing_balance'], $closingBalance, 2);
        }

        // Apply grouping
        if ($grouping === 'parent') {
            $rows = $this->groupByParent($rows, $accounts);
        } elseif ($grouping === 'type') {
            $rows = $this->groupByType($rows);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'count' => count($rows),
        ];
    }

    /**
     * Query aggregated balances from journal entry lines.
     */
    private function queryBalances(int $tenantId, array $accountIds, ?string $fromDate, ?string $toDate): \Illuminate\Support\Collection
    {
        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_entry_lines.account_id', $accountIds);

        if ($fromDate) {
            $query->where('journal_entries.date', '>=', $fromDate);
        }

        // For opening balances, toDate means "before this date"
        if ($toDate && ! $fromDate) {
            $query->where('journal_entries.date', '<', $toDate);
        } elseif ($toDate) {
            $query->where('journal_entries.date', '<=', $toDate);
        }

        return $query
            ->select(
                'journal_entry_lines.account_id',
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit'),
            )
            ->groupBy('journal_entry_lines.account_id')
            ->get()
            ->keyBy('account_id');
    }

    /**
     * Group rows by parent account.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupByParent(array $rows, \Illuminate\Database\Eloquent\Collection $accounts): array
    {
        // Bulk-load all parent accounts to avoid N+1
        $parentIds = array_filter(array_unique(array_column($rows, 'parent_id')));
        $parents = ! empty($parentIds)
            ? Account::whereIn('id', $parentIds)->get()->keyBy('id')
            : collect();

        $grouped = [];

        foreach ($rows as $row) {
            $parentId = $row['parent_id'] ?? 'ungrouped';
            if (! isset($grouped[$parentId])) {
                $parent = $parentId !== 'ungrouped' ? $parents->get($parentId) : null;
                $grouped[$parentId] = [
                    'group_code' => $parent?->code ?? '',
                    'group_name_ar' => $parent?->name_ar ?? 'أخرى',
                    'group_name_en' => $parent?->name_en ?? 'Other',
                    'accounts' => [],
                    'subtotal' => '0.00',
                ];
            }

            $grouped[$parentId]['accounts'][] = $row;
            $grouped[$parentId]['subtotal'] = bcadd($grouped[$parentId]['subtotal'], $row['closing_balance'], 2);
        }

        // Sort by group code
        uasort($grouped, fn ($a, $b) => strcmp($a['group_code'], $b['group_code']));

        return array_values($grouped);
    }

    /**
     * Group rows by account type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupByType(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $type = $row['type'];
            if (! isset($grouped[$type])) {
                $accountType = AccountType::from($type);
                $grouped[$type] = [
                    'group_code' => $type,
                    'group_name_ar' => $accountType->labelAr(),
                    'group_name_en' => $accountType->label(),
                    'accounts' => [],
                    'subtotal' => '0.00',
                ];
            }

            $grouped[$type]['accounts'][] = $row;
            $grouped[$type]['subtotal'] = bcadd($grouped[$type]['subtotal'], $row['closing_balance'], 2);
        }

        return array_values($grouped);
    }

    /**
     * Calculate variance between current and prior period data.
     *
     * @return array<string, mixed>
     */
    private function calculateVariance(array $current, array $prior): array
    {
        $currentTotal = (float) ($current['totals']['closing_balance'] ?? 0);
        $priorTotal = (float) ($prior['totals']['closing_balance'] ?? 0);
        $change = $currentTotal - $priorTotal;

        return [
            'closing_balance_change' => number_format($change, 2, '.', ''),
            'closing_balance_change_pct' => $priorTotal != 0
                ? number_format(($change / abs($priorTotal)) * 100, 1, '.', '')
                : '0.0',
            'current_total' => $current['totals']['closing_balance'] ?? '0.00',
            'prior_total' => $prior['totals']['closing_balance'] ?? '0.00',
        ];
    }
}
