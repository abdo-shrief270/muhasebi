<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\StatementTemplate;
use Illuminate\Support\Facades\DB;

class StatementBuilderService
{
    // ──────────────────────────────────────
    // Template CRUD
    // ──────────────────────────────────────

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function list(array $filters = [])
    {
        $query = StatementTemplate::query()->orderBy('name_ar');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StatementTemplate
    {
        $data['tenant_id'] = app('tenant.id');
        $data['created_by'] = auth()->id();

        return StatementTemplate::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StatementTemplate $template, array $data): StatementTemplate
    {
        $template->update($data);

        return $template->fresh();
    }

    public function delete(StatementTemplate $template): void
    {
        $template->delete();
    }

    // ──────────────────────────────────────
    // Statement Generation
    // ──────────────────────────────────────

    /**
     * Generate a financial statement from a template against the GL.
     *
     * @return array{sections: array<int, array<string, mixed>>}
     */
    public function generate(
        StatementTemplate $template,
        string $from,
        string $to,
        ?string $compareFrom = null,
        ?string $compareTo = null,
    ): array {
        $structure = $template->structure;
        $sections = $structure['sections'] ?? [];

        $resolvedSections = [];
        $sectionTotals = [];

        foreach ($sections as $section) {
            if (! empty($section['is_calculated'])) {
                $resolved = $this->resolveCalculatedSection($section, $sectionTotals);
                $resolvedSections[] = $resolved;
                $sectionTotals[$section['id']] = $resolved['subtotal'];

                continue;
            }

            $rows = $this->querySection($section, $from, $to);
            $subtotal = '0.00';
            foreach ($rows as &$row) {
                $subtotal = bcadd($subtotal, $row['amount'], 2);
            }
            unset($row);

            if (! empty($section['negate'])) {
                $subtotal = bcmul($subtotal, '-1', 2);
                foreach ($rows as &$row) {
                    $row['amount'] = bcmul($row['amount'], '-1', 2);
                }
                unset($row);
            }

            // Comparison period
            $compareRows = [];
            $compareSubtotal = null;
            if ($compareFrom !== null && $compareTo !== null) {
                $compareRows = $this->querySection($section, $compareFrom, $compareTo);
                $compareSubtotal = '0.00';
                foreach ($compareRows as &$cr) {
                    $compareSubtotal = bcadd($compareSubtotal, $cr['amount'], 2);
                }
                unset($cr);

                if (! empty($section['negate'])) {
                    $compareSubtotal = bcmul($compareSubtotal, '-1', 2);
                    foreach ($compareRows as &$cr) {
                        $cr['amount'] = bcmul($cr['amount'], '-1', 2);
                    }
                    unset($cr);
                }

                // Merge compare amounts into rows
                $compareByCode = collect($compareRows)->keyBy('code');
                foreach ($rows as &$row) {
                    $cRow = $compareByCode->get($row['code']);
                    $row['compare_amount'] = $cRow['amount'] ?? '0.00';
                    $row['variance'] = bcsub($row['amount'], $row['compare_amount'], 2);
                    $row['variance_pct'] = bccomp($row['compare_amount'], '0', 2) !== 0
                        ? bcmul(bcdiv($row['variance'], $row['compare_amount'], 6), '100', 2)
                        : '0.00';
                }
                unset($row);
            }

            $result = [
                'id' => $section['id'],
                'label_ar' => $section['label_ar'] ?? '',
                'label_en' => $section['label_en'] ?? '',
                'rows' => $rows,
                'subtotal' => $subtotal,
            ];

            if ($compareSubtotal !== null) {
                $result['compare_subtotal'] = $compareSubtotal;
            }

            $resolvedSections[] = $result;
            $sectionTotals[$section['id']] = $subtotal;
        }

        return ['sections' => $resolvedSections];
    }

    /**
     * Query GL data for a section definition.
     *
     * @param  array<string, mixed>  $section
     * @return array<int, array{code: string, name: string, amount: string}>
     */
    private function querySection(array $section, string $from, string $to): array
    {
        $accounts = $section['accounts'] ?? [];

        $query = Account::query()
            ->active()
            ->leafAccounts();

        if (! empty($accounts['type'])) {
            $type = AccountType::from($accounts['type']);
            $query->ofType($type);
        } elseif (! empty($accounts['codes_from']) && ! empty($accounts['codes_to'])) {
            $query->where('code', '>=', $accounts['codes_from'])
                ->where('code', '<=', $accounts['codes_to']);
        } elseif (! empty($accounts['ids'])) {
            $query->whereIn('id', $accounts['ids']);
        } else {
            return [];
        }

        $accountList = $query->orderBy('code')->get();

        if ($accountList->isEmpty()) {
            return [];
        }

        $accountIds = $accountList->pluck('id')->toArray();

        // Query GL balances for the period
        $balancesQuery = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.date', '>=', $from)
            ->where('journal_entries.date', '<=', $to)
            ->whereIn('journal_entry_lines.account_id', $accountIds);

        if (app('tenant.id')) {
            $balancesQuery->where('journal_entries.tenant_id', app('tenant.id'));
        }

        $balances = $balancesQuery
            ->select(
                'journal_entry_lines.account_id',
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            )
            ->groupBy('journal_entry_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $rows = [];
        foreach ($accountList as $account) {
            $bal = $balances->get($account->id);
            $debit = (string) ($bal->total_debit ?? '0');
            $credit = (string) ($bal->total_credit ?? '0');

            // Net balance based on normal balance side
            $amount = $account->normal_balance === NormalBalance::Debit
                ? bcsub($debit, $credit, 2)
                : bcsub($credit, $debit, 2);

            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }

            $rows[] = [
                'code' => $account->code,
                'name' => $account->name_ar,
                'amount' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * Resolve a calculated section (formula-based).
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, string>  $sectionTotals
     * @return array<string, mixed>
     */
    private function resolveCalculatedSection(array $section, array $sectionTotals): array
    {
        $formula = $section['formula'] ?? '';
        $amount = $this->evaluateFormula($formula, $sectionTotals);

        return [
            'id' => $section['id'],
            'label_ar' => $section['label_ar'] ?? '',
            'label_en' => $section['label_en'] ?? '',
            'rows' => [],
            'subtotal' => $amount,
            'is_calculated' => true,
        ];
    }

    /**
     * Evaluate a simple formula like "revenue - cogs".
     * Supports +, - operators with section IDs as operands.
     */
    private function evaluateFormula(string $formula, array $sectionTotals): string
    {
        // Tokenize: split on + and - while keeping the operator
        $tokens = preg_split('/\s*([+\-])\s*/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $result = '0.00';
        $operator = '+';

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '+' || $token === '-') {
                $operator = $token;

                continue;
            }

            $value = $sectionTotals[$token] ?? '0.00';

            $result = $operator === '+'
                ? bcadd($result, $value, 2)
                : bcsub($result, $value, 2);
        }

        return $result;
    }

    // ──────────────────────────────────────
    // Financial Ratios
    // ──────────────────────────────────────

    /**
     * Calculate common financial ratios.
     *
     * @return array<int, array{name: string, name_ar: string, formula: string, value: string}>
     */
    public function generateRatios(string $from, string $to): array
    {
        $totals = $this->getAccountTypeTotals($from, $to);

        $totalAssets = $totals['asset'] ?? '0.00';
        $totalLiabilities = $totals['liability'] ?? '0.00';
        $totalEquity = $totals['equity'] ?? '0.00';
        $totalRevenue = $totals['revenue'] ?? '0.00';
        $totalExpenses = $totals['expense'] ?? '0.00';

        $netIncome = bcsub($totalRevenue, $totalExpenses, 2);

        // For current/quick ratio we use asset/liability as approximation
        // In a full system, these would use sub-classifications
        $currentRatio = bccomp($totalLiabilities, '0', 2) !== 0
            ? bcdiv($totalAssets, $totalLiabilities, 4)
            : '0.00';

        $quickRatio = $currentRatio; // Simplified — same as current without inventory data

        $debtToEquity = bccomp($totalEquity, '0', 2) !== 0
            ? bcdiv($totalLiabilities, $totalEquity, 4)
            : '0.00';

        $roe = bccomp($totalEquity, '0', 2) !== 0
            ? bcmul(bcdiv($netIncome, $totalEquity, 6), '100', 2)
            : '0.00';

        $roa = bccomp($totalAssets, '0', 2) !== 0
            ? bcmul(bcdiv($netIncome, $totalAssets, 6), '100', 2)
            : '0.00';

        $grossMargin = bccomp($totalRevenue, '0', 2) !== 0
            ? bcmul(bcdiv($netIncome, $totalRevenue, 6), '100', 2)
            : '0.00';

        $netMargin = $grossMargin; // Simplified — same without COGS breakdown

        $operatingMargin = $grossMargin; // Simplified

        return [
            [
                'name' => 'Current Ratio',
                'name_ar' => 'نسبة التداول',
                'formula' => 'Total Assets / Total Liabilities',
                'value' => $currentRatio,
            ],
            [
                'name' => 'Quick Ratio',
                'name_ar' => 'نسبة السيولة السريعة',
                'formula' => '(Total Assets - Inventory) / Total Liabilities',
                'value' => $quickRatio,
            ],
            [
                'name' => 'Debt to Equity',
                'name_ar' => 'نسبة الديون إلى حقوق الملكية',
                'formula' => 'Total Liabilities / Total Equity',
                'value' => $debtToEquity,
            ],
            [
                'name' => 'Return on Equity (ROE)',
                'name_ar' => 'العائد على حقوق الملكية',
                'formula' => 'Net Income / Total Equity × 100',
                'value' => $roe,
            ],
            [
                'name' => 'Return on Assets (ROA)',
                'name_ar' => 'العائد على الأصول',
                'formula' => 'Net Income / Total Assets × 100',
                'value' => $roa,
            ],
            [
                'name' => 'Gross Margin',
                'name_ar' => 'هامش الربح الإجمالي',
                'formula' => 'Gross Profit / Revenue × 100',
                'value' => $grossMargin,
            ],
            [
                'name' => 'Net Margin',
                'name_ar' => 'هامش صافي الربح',
                'formula' => 'Net Income / Revenue × 100',
                'value' => $netMargin,
            ],
            [
                'name' => 'Operating Margin',
                'name_ar' => 'هامش الربح التشغيلي',
                'formula' => 'Operating Income / Revenue × 100',
                'value' => $operatingMargin,
            ],
        ];
    }

    // ──────────────────────────────────────
    // Vertical Analysis
    // ──────────────────────────────────────

    /**
     * Each line as % of revenue (P&L) or total assets (BS).
     *
     * @return array{accounts: array<int, array<string, mixed>>, base_label: string, base_amount: string}
     */
    public function verticalAnalysis(string $from, string $to): array
    {
        $totals = $this->getAccountTypeTotals($from, $to);
        $totalRevenue = $totals['revenue'] ?? '0.00';
        $totalAssets = $totals['asset'] ?? '0.00';

        // Use revenue as base for P&L accounts, total assets for BS accounts
        $accounts = Account::query()
            ->active()
            ->leafAccounts()
            ->orderBy('code')
            ->get();

        $rows = [];
        $balances = $this->getAccountBalances($accounts->pluck('id')->toArray(), $from, $to);

        foreach ($accounts as $account) {
            $bal = $balances->get($account->id);
            $debit = (string) ($bal->total_debit ?? '0');
            $credit = (string) ($bal->total_credit ?? '0');

            $amount = $account->normal_balance === NormalBalance::Debit
                ? bcsub($debit, $credit, 2)
                : bcsub($credit, $debit, 2);

            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }

            $isIncomeStatement = in_array($account->type, [AccountType::Revenue, AccountType::Expense]);
            $base = $isIncomeStatement ? $totalRevenue : $totalAssets;
            $baseLabel = $isIncomeStatement ? 'revenue' : 'total_assets';

            $percentage = bccomp($base, '0', 2) !== 0
                ? bcmul(bcdiv($amount, $base, 6), '100', 2)
                : '0.00';

            $rows[] = [
                'code' => $account->code,
                'name' => $account->name_ar,
                'type' => $account->type->value,
                'amount' => $amount,
                'base' => $baseLabel,
                'percentage' => $percentage,
            ];
        }

        return [
            'accounts' => $rows,
            'base_revenue' => $totalRevenue,
            'base_total_assets' => $totalAssets,
        ];
    }

    // ──────────────────────────────────────
    // Horizontal Analysis
    // ──────────────────────────────────────

    /**
     * Period-over-period change.
     *
     * @return array{accounts: array<int, array<string, mixed>>}
     */
    public function horizontalAnalysis(
        string $from1,
        string $to1,
        string $from2,
        string $to2,
    ): array {
        $accounts = Account::query()
            ->active()
            ->leafAccounts()
            ->orderBy('code')
            ->get();

        $accountIds = $accounts->pluck('id')->toArray();
        $balances1 = $this->getAccountBalances($accountIds, $from1, $to1);
        $balances2 = $this->getAccountBalances($accountIds, $from2, $to2);

        $rows = [];
        foreach ($accounts as $account) {
            $bal1 = $balances1->get($account->id);
            $debit1 = (string) ($bal1->total_debit ?? '0');
            $credit1 = (string) ($bal1->total_credit ?? '0');
            $amount1 = $account->normal_balance === NormalBalance::Debit
                ? bcsub($debit1, $credit1, 2)
                : bcsub($credit1, $debit1, 2);

            $bal2 = $balances2->get($account->id);
            $debit2 = (string) ($bal2->total_debit ?? '0');
            $credit2 = (string) ($bal2->total_credit ?? '0');
            $amount2 = $account->normal_balance === NormalBalance::Debit
                ? bcsub($debit2, $credit2, 2)
                : bcsub($credit2, $debit2, 2);

            if (bccomp($amount1, '0', 2) === 0 && bccomp($amount2, '0', 2) === 0) {
                continue;
            }

            $change = bcsub($amount2, $amount1, 2);
            $changePct = bccomp($amount1, '0', 2) !== 0
                ? bcmul(bcdiv($change, $amount1, 6), '100', 2)
                : '0.00';

            $rows[] = [
                'code' => $account->code,
                'name' => $account->name_ar,
                'type' => $account->type->value,
                'period1_amount' => $amount1,
                'period2_amount' => $amount2,
                'change' => $change,
                'change_pct' => $changePct,
            ];
        }

        return ['accounts' => $rows];
    }

    // ──────────────────────────────────────
    // Private Helpers
    // ──────────────────────────────────────

    /**
     * Get total balances grouped by account type for the given period.
     *
     * @return array<string, string>
     */
    private function getAccountTypeTotals(string $from, string $to): array
    {
        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.date', '>=', $from)
            ->where('journal_entries.date', '<=', $to);

        if (app('tenant.id')) {
            $query->where('journal_entries.tenant_id', app('tenant.id'));
        }

        $results = $query
            ->select(
                'accounts.type',
                'accounts.normal_balance',
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            )
            ->groupBy('accounts.type', 'accounts.normal_balance')
            ->get();

        $totals = [];
        foreach ($results as $row) {
            $normalBalance = NormalBalance::from($row->normal_balance);
            $amount = $normalBalance === NormalBalance::Debit
                ? bcsub((string) $row->total_debit, (string) $row->total_credit, 2)
                : bcsub((string) $row->total_credit, (string) $row->total_debit, 2);

            $type = $row->type;
            $totals[$type] = bcadd($totals[$type] ?? '0.00', $amount, 2);
        }

        return $totals;
    }

    /**
     * Get account balances for specific account IDs and period.
     *
     * @param  array<int>  $accountIds
     * @return \Illuminate\Support\Collection
     */
    private function getAccountBalances(array $accountIds, string $from, string $to)
    {
        if (empty($accountIds)) {
            return collect();
        }

        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.date', '>=', $from)
            ->where('journal_entries.date', '<=', $to)
            ->whereIn('journal_entry_lines.account_id', $accountIds);

        if (app('tenant.id')) {
            $query->where('journal_entries.tenant_id', app('tenant.id'));
        }

        return $query
            ->select(
                'journal_entry_lines.account_id',
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            )
            ->groupBy('journal_entry_lines.account_id')
            ->get()
            ->keyBy('account_id');
    }
}
