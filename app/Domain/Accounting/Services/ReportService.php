<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Generate a trial balance report.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, string>}
     */
    public function trialBalance(?string $fromDate = null, ?string $toDate = null): array
    {
        // Get all active leaf accounts
        $accounts = Account::query()
            ->active()
            ->leafAccounts()
            ->orderBy('code')
            ->get();

        // Build opening balances query (all posted entries before fromDate)
        // Only compute opening balances when a fromDate is specified
        $openingBalances = collect();

        if ($fromDate) {
            $openingQuery = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entries.status', JournalEntryStatus::Posted->value)
                ->whereNull('journal_entries.deleted_at')
                ->where('journal_entries.date', '<', $fromDate);

            if (app('tenant.id')) {
                $openingQuery->where('journal_entries.tenant_id', app('tenant.id'));
            }

            $openingBalances = $openingQuery
                ->select(
                    'journal_entry_lines.account_id',
                    DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                    DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
                )
                ->groupBy('journal_entry_lines.account_id')
                ->get()
                ->keyBy('account_id');
        }

        // Build period movements query (posted entries within date range)
        $periodQuery = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at');

        if (app('tenant.id')) {
            $periodQuery->where('journal_entries.tenant_id', app('tenant.id'));
        }

        if ($fromDate) {
            $periodQuery->where('journal_entries.date', '>=', $fromDate);
        }

        if ($toDate) {
            $periodQuery->where('journal_entries.date', '<=', $toDate);
        }

        $periodMovements = $periodQuery
            ->select(
                'journal_entry_lines.account_id',
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            )
            ->groupBy('journal_entry_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $rows = [];
        $totals = [
            'opening_debit' => '0.00',
            'opening_credit' => '0.00',
            'period_debit' => '0.00',
            'period_credit' => '0.00',
            'closing_debit' => '0.00',
            'closing_credit' => '0.00',
        ];

        foreach ($accounts as $account) {
            $opening = $openingBalances->get($account->id);
            $openingDebit = (string) ($opening->total_debit ?? '0');
            $openingCredit = (string) ($opening->total_credit ?? '0');

            $period = $periodMovements->get($account->id);
            $periodDebit = (string) ($period->total_debit ?? '0');
            $periodCredit = (string) ($period->total_credit ?? '0');

            // Calculate opening balance as debit/credit columns
            $openingBalance = $account->normal_balance === NormalBalance::Debit
                ? bcsub($openingDebit, $openingCredit, 2)
                : bcsub($openingCredit, $openingDebit, 2);

            $openingDebitCol = '0.00';
            $openingCreditCol = '0.00';

            if (bccomp($openingBalance, '0', 2) > 0) {
                if ($account->normal_balance === NormalBalance::Debit) {
                    $openingDebitCol = $openingBalance;
                } else {
                    $openingCreditCol = $openingBalance;
                }
            } elseif (bccomp($openingBalance, '0', 2) < 0) {
                if ($account->normal_balance === NormalBalance::Debit) {
                    $openingCreditCol = bcmul($openingBalance, '-1', 2);
                } else {
                    $openingDebitCol = bcmul($openingBalance, '-1', 2);
                }
            }

            // Calculate closing balance
            $closingDebitTotal = bcadd($openingDebit, $periodDebit, 2);
            $closingCreditTotal = bcadd($openingCredit, $periodCredit, 2);

            $closingBalance = $account->normal_balance === NormalBalance::Debit
                ? bcsub($closingDebitTotal, $closingCreditTotal, 2)
                : bcsub($closingCreditTotal, $closingDebitTotal, 2);

            $closingDebitCol = '0.00';
            $closingCreditCol = '0.00';

            if (bccomp($closingBalance, '0', 2) > 0) {
                if ($account->normal_balance === NormalBalance::Debit) {
                    $closingDebitCol = $closingBalance;
                } else {
                    $closingCreditCol = $closingBalance;
                }
            } elseif (bccomp($closingBalance, '0', 2) < 0) {
                if ($account->normal_balance === NormalBalance::Debit) {
                    $closingCreditCol = bcmul($closingBalance, '-1', 2);
                } else {
                    $closingDebitCol = bcmul($closingBalance, '-1', 2);
                }
            }

            // Skip accounts with zero activity (no opening and no period movement)
            if (
                bccomp($openingDebitCol, '0.00', 2) === 0
                && bccomp($openingCreditCol, '0.00', 2) === 0
                && bccomp($periodDebit, '0.00', 2) === 0
                && bccomp($periodCredit, '0.00', 2) === 0
            ) {
                continue;
            }

            $row = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name_ar' => $account->name_ar,
                'account_name_en' => $account->name_en,
                'account_type' => $account->type->value,
                'opening_debit' => $openingDebitCol,
                'opening_credit' => $openingCreditCol,
                'period_debit' => number_format((float) $periodDebit, 2, '.', ''),
                'period_credit' => number_format((float) $periodCredit, 2, '.', ''),
                'closing_debit' => $closingDebitCol,
                'closing_credit' => $closingCreditCol,
            ];

            $rows[] = $row;

            // Accumulate totals
            $totals['opening_debit'] = bcadd($totals['opening_debit'], $openingDebitCol, 2);
            $totals['opening_credit'] = bcadd($totals['opening_credit'], $openingCreditCol, 2);
            $totals['period_debit'] = bcadd($totals['period_debit'], $row['period_debit'], 2);
            $totals['period_credit'] = bcadd($totals['period_credit'], $row['period_credit'], 2);
            $totals['closing_debit'] = bcadd($totals['closing_debit'], $closingDebitCol, 2);
            $totals['closing_credit'] = bcadd($totals['closing_credit'], $closingCreditCol, 2);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Generate an account ledger report with running balance.
     *
     * @return array{opening_balance: string, transactions: array<int, array<string, mixed>>, closing_balance: string}
     */
    public function accountLedger(Account $account, ?string $fromDate = null, ?string $toDate = null): array
    {
        $isDebitNormal = $account->normal_balance === NormalBalance::Debit;

        // Calculate opening balance (all posted entries before fromDate)
        $openingBalance = '0.00';

        if ($fromDate) {
            $openingData = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_entry_lines.account_id', $account->id)
                ->where('journal_entries.status', JournalEntryStatus::Posted->value)
                ->where('journal_entries.date', '<', $fromDate)
                ->whereNull('journal_entries.deleted_at')
                ->select(
                    DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                    DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
                )
                ->first();

            if ($openingData) {
                $openingBalance = $isDebitNormal
                    ? bcsub((string) $openingData->total_debit, (string) $openingData->total_credit, 2)
                    : bcsub((string) $openingData->total_credit, (string) $openingData->total_debit, 2);
            }
        }

        // Get transactions within date range
        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $account->id)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->select(
                'journal_entries.date',
                'journal_entries.entry_number',
                'journal_entries.description as entry_description',
                'journal_entry_lines.description as line_description',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit'
            )
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.entry_number');

        if ($fromDate) {
            $query->where('journal_entries.date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->where('journal_entries.date', '<=', $toDate);
        }

        $lines = $query->get();

        $runningBalance = $openingBalance;
        $transactions = [];

        foreach ($lines as $line) {
            $debit = (string) $line->debit;
            $credit = (string) $line->credit;

            if ($isDebitNormal) {
                $runningBalance = bcadd(bcsub($runningBalance, $credit, 2), $debit, 2);
            } else {
                $runningBalance = bcadd(bcsub($runningBalance, $debit, 2), $credit, 2);
            }

            $transactions[] = [
                'date' => $line->date,
                'entry_number' => $line->entry_number,
                'description' => $line->line_description ?? $line->entry_description,
                'debit' => number_format((float) $debit, 2, '.', ''),
                'credit' => number_format((float) $credit, 2, '.', ''),
                'running_balance' => $runningBalance,
            ];
        }

        return [
            'opening_balance' => $openingBalance,
            'transactions' => $transactions,
            'closing_balance' => $runningBalance,
        ];
    }

    // ──────────────────────────────────────
    // Income Statement
    // ──────────────────────────────────────

    /**
     * Generate an income statement (P&L) for a date range.
     *
     * @return array{revenue: array, expenses: array, net_income: string, period: array}
     */
    public function incomeStatement(?string $fromDate = null, ?string $toDate = null): array
    {
        $revenueAccounts = Account::query()
            ->active()
            ->leafAccounts()
            ->ofType(AccountType::Revenue)
            ->orderBy('code')
            ->get();

        $expenseAccounts = Account::query()
            ->active()
            ->leafAccounts()
            ->ofType(AccountType::Expense)
            ->orderBy('code')
            ->get();

        $allAccounts = $revenueAccounts->merge($expenseAccounts);
        $balances = $this->getAccountBalances(
            [AccountType::Revenue, AccountType::Expense],
            $fromDate,
            $toDate
        );

        // Revenue: credit - debit (credit-normal)
        $revenueGroups = $this->groupAccountsByParent($revenueAccounts, $balances, false);
        $revenueTotal = '0.00';
        foreach ($revenueGroups as $group) {
            $revenueTotal = bcadd($revenueTotal, $group['subtotal'], 2);
        }

        // Expenses: debit - credit (debit-normal)
        $expenseGroups = $this->groupAccountsByParent($expenseAccounts, $balances, true);
        $expensesTotal = '0.00';
        foreach ($expenseGroups as $group) {
            $expensesTotal = bcadd($expensesTotal, $group['subtotal'], 2);
        }

        $netIncome = bcsub($revenueTotal, $expensesTotal, 2);

        return [
            'revenue' => [
                'groups' => $revenueGroups,
                'total' => $revenueTotal,
            ],
            'expenses' => [
                'groups' => $expenseGroups,
                'total' => $expensesTotal,
            ],
            'net_income' => $netIncome,
            'period' => ['from' => $fromDate, 'to' => $toDate],
        ];
    }

    // ──────────────────────────────────────
    // Balance Sheet
    // ──────────────────────────────────────

    /**
     * Generate a balance sheet as of a specific date.
     *
     * @return array{assets: array, liabilities: array, equity: array, total_liabilities_and_equity: string, is_balanced: bool, as_of_date: string}
     */
    public function balanceSheet(?string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? date('Y-m-d');

        $assetAccounts = Account::query()->active()->leafAccounts()->ofType(AccountType::Asset)->orderBy('code')->get();
        $liabilityAccounts = Account::query()->active()->leafAccounts()->ofType(AccountType::Liability)->orderBy('code')->get();
        $equityAccounts = Account::query()->active()->leafAccounts()->ofType(AccountType::Equity)->orderBy('code')->get();

        // Cumulative balances up to asOfDate (no fromDate, only toDate)
        $balances = $this->getAccountBalances(
            [AccountType::Asset, AccountType::Liability, AccountType::Equity],
            null,
            $asOfDate
        );

        // Assets: debit - credit
        $assetGroups = $this->groupAccountsByParent($assetAccounts, $balances, true);
        $assetsTotal = '0.00';
        foreach ($assetGroups as $group) {
            $assetsTotal = bcadd($assetsTotal, $group['subtotal'], 2);
        }

        // Liabilities: credit - debit
        $liabilityGroups = $this->groupAccountsByParent($liabilityAccounts, $balances, false);
        $liabilitiesTotal = '0.00';
        foreach ($liabilityGroups as $group) {
            $liabilitiesTotal = bcadd($liabilitiesTotal, $group['subtotal'], 2);
        }

        // Equity: credit - debit
        $equityGroups = $this->groupAccountsByParent($equityAccounts, $balances, false);
        $equitySubtotal = '0.00';
        foreach ($equityGroups as $group) {
            $equitySubtotal = bcadd($equitySubtotal, $group['subtotal'], 2);
        }

        // Calculate current year net income: fiscal year start to asOfDate
        $fiscalYearStart = substr($asOfDate, 0, 4) . '-01-01';
        $incomeData = $this->incomeStatement($fiscalYearStart, $asOfDate);
        $netIncome = $incomeData['net_income'];

        $equityTotal = bcadd($equitySubtotal, $netIncome, 2);
        $totalLiabilitiesAndEquity = bcadd($liabilitiesTotal, $equityTotal, 2);
        $isBalanced = bccomp($assetsTotal, $totalLiabilitiesAndEquity, 2) === 0;

        return [
            'assets' => [
                'groups' => $assetGroups,
                'total' => $assetsTotal,
            ],
            'liabilities' => [
                'groups' => $liabilityGroups,
                'total' => $liabilitiesTotal,
            ],
            'equity' => [
                'groups' => $equityGroups,
                'net_income' => $netIncome,
                'total' => $equityTotal,
            ],
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'is_balanced' => $isBalanced,
            'as_of_date' => $asOfDate,
        ];
    }

    // ──────────────────────────────────────
    // Cash Flow Statement
    // ──────────────────────────────────────

    /**
     * Generate a cash flow statement (indirect method) for a date range.
     *
     * @return array{operating: array, investing: array, financing: array, net_change: string, opening_cash: string, closing_cash: string, period: array}
     */
    public function cashFlowStatement(?string $fromDate = null, ?string $toDate = null): array
    {
        // 1. Start with net income
        $incomeData = $this->incomeStatement($fromDate, $toDate);
        $netIncome = $incomeData['net_income'];

        // Helper to get the balance change for specific account codes within the period
        $getBalanceChange = function (array $codes, bool $isDebitNormal) use ($fromDate, $toDate): string {
            $accounts = Account::query()
                ->active()
                ->leafAccounts()
                ->whereIn('code', $codes)
                ->pluck('id')
                ->toArray();

            if (empty($accounts)) {
                return '0.00';
            }

            $balances = $this->getAccountBalancesForIds($accounts, $fromDate, $toDate);

            $total = '0.00';
            foreach ($balances as $bal) {
                $amount = $isDebitNormal
                    ? bcsub((string) $bal->total_debit, (string) $bal->total_credit, 2)
                    : bcsub((string) $bal->total_credit, (string) $bal->total_debit, 2);
                $total = bcadd($total, $amount, 2);
            }

            return $total;
        };

        // Helper to get balance change for accounts matching a code prefix
        $getBalanceChangeByPrefix = function (string $prefix, bool $isDebitNormal, array $excludePrefixes = []) use ($fromDate, $toDate): string {
            $query = Account::query()
                ->active()
                ->leafAccounts()
                ->where('code', 'like', $prefix . '%');

            foreach ($excludePrefixes as $excludePrefix) {
                $query->where('code', 'not like', $excludePrefix . '%');
            }

            $accountIds = $query->pluck('id')->toArray();

            if (empty($accountIds)) {
                return '0.00';
            }

            $balances = $this->getAccountBalancesForIds($accountIds, $fromDate, $toDate);

            $total = '0.00';
            foreach ($balances as $bal) {
                $amount = $isDebitNormal
                    ? bcsub((string) $bal->total_debit, (string) $bal->total_credit, 2)
                    : bcsub((string) $bal->total_credit, (string) $bal->total_debit, 2);
                $total = bcadd($total, $amount, 2);
            }

            return $total;
        };

        // 2. Operating activities adjustments
        $adjustments = [];

        // Depreciation expense (code starts with 5280)
        $depreciation = $getBalanceChangeByPrefix('5280', true);
        if (bccomp($depreciation, '0.00', 2) !== 0) {
            $adjustments[] = [
                'description_ar' => 'مصروفات الاستهلاك',
                'description_en' => 'Depreciation expense',
                'amount' => $depreciation,
            ];
        }

        // Working capital changes
        $workingCapitalChanges = [];

        // Accounts receivable (1121): increase in AR is negative for cash flow
        $arChange = $getBalanceChange([config('accounting.default_accounts.accounts_receivable')], true);
        if (bccomp($arChange, '0.00', 2) !== 0) {
            $workingCapitalChanges[] = [
                'description_ar' => 'التغير في حسابات المدينين',
                'description_en' => 'Change in accounts receivable',
                'amount' => bcmul($arChange, '-1', 2), // negate: increase in asset = cash outflow
            ];
        }

        // Accounts payable (2111): increase in AP is positive for cash flow
        $apChange = $getBalanceChange(['2111'], false);
        if (bccomp($apChange, '0.00', 2) !== 0) {
            $workingCapitalChanges[] = [
                'description_ar' => 'التغير في حسابات الدائنين',
                'description_en' => 'Change in accounts payable',
                'amount' => $apChange,
            ];
        }

        // Inventory (1131, 1132): increase is negative
        $inventoryChange = $getBalanceChange(['1131', '1132'], true);
        if (bccomp($inventoryChange, '0.00', 2) !== 0) {
            $workingCapitalChanges[] = [
                'description_ar' => 'التغير في المخزون',
                'description_en' => 'Change in inventory',
                'amount' => bcmul($inventoryChange, '-1', 2),
            ];
        }

        // Prepaid (1141, 1142): increase is negative
        $prepaidChange = $getBalanceChange(['1141', '1142'], true);
        if (bccomp($prepaidChange, '0.00', 2) !== 0) {
            $workingCapitalChanges[] = [
                'description_ar' => 'التغير في المصروفات المدفوعة مقدما',
                'description_en' => 'Change in prepaid expenses',
                'amount' => bcmul($prepaidChange, '-1', 2),
            ];
        }

        // Accrued expenses (2121, 2122): increase is positive
        $accruedChange = $getBalanceChange(['2121', '2122'], false);
        if (bccomp($accruedChange, '0.00', 2) !== 0) {
            $workingCapitalChanges[] = [
                'description_ar' => 'التغير في المصروفات المستحقة',
                'description_en' => 'Change in accrued expenses',
                'amount' => $accruedChange,
            ];
        }

        // Taxes payable (2131-2134)
        $taxesChange = $getBalanceChange([
                config('accounting.default_accounts.vat_output'),
                config('accounting.default_accounts.wht_services'),
                config('accounting.default_accounts.wht_supplies'),
                config('accounting.default_accounts.wht_equipment'),
            ], false);
        if (bccomp($taxesChange, '0.00', 2) !== 0) {
            $workingCapitalChanges[] = [
                'description_ar' => 'التغير في الضرائب المستحقة',
                'description_en' => 'Change in taxes payable',
                'amount' => $taxesChange,
            ];
        }

        $adjustmentsTotal = '0.00';
        foreach ($adjustments as $adj) {
            $adjustmentsTotal = bcadd($adjustmentsTotal, $adj['amount'], 2);
        }

        $wcTotal = '0.00';
        foreach ($workingCapitalChanges as $wc) {
            $wcTotal = bcadd($wcTotal, $wc['amount'], 2);
        }

        $operatingTotal = bcadd(bcadd($netIncome, $adjustmentsTotal, 2), $wcTotal, 2);

        // 3. Investing activities: fixed assets (12xx, excluding accumulated depreciation 1260)
        $investingChange = $getBalanceChangeByPrefix('12', true, ['1260']);
        $investingItems = [];
        if (bccomp($investingChange, '0.00', 2) !== 0) {
            $investingItems[] = [
                'description_ar' => 'شراء / بيع أصول ثابتة',
                'description_en' => 'Purchase/sale of fixed assets',
                'amount' => bcmul($investingChange, '-1', 2), // increase in assets = cash outflow
            ];
        }

        $investingTotal = '0.00';
        foreach ($investingItems as $item) {
            $investingTotal = bcadd($investingTotal, $item['amount'], 2);
        }

        // 4. Financing activities: equity (3xxx) and long-term loans (2210)
        $financingItems = [];

        $equityChange = $getBalanceChangeByPrefix('3', false);
        if (bccomp($equityChange, '0.00', 2) !== 0) {
            $financingItems[] = [
                'description_ar' => 'التغير في حقوق الملكية',
                'description_en' => 'Change in equity',
                'amount' => $equityChange,
            ];
        }

        $loansChange = $getBalanceChange(['2210'], false);
        if (bccomp($loansChange, '0.00', 2) !== 0) {
            $financingItems[] = [
                'description_ar' => 'التغير في القروض طويلة الأجل',
                'description_en' => 'Change in long-term loans',
                'amount' => $loansChange,
            ];
        }

        $financingTotal = '0.00';
        foreach ($financingItems as $item) {
            $financingTotal = bcadd($financingTotal, $item['amount'], 2);
        }

        // 5. Cash balances
        $netChange = bcadd(bcadd($operatingTotal, $investingTotal, 2), $financingTotal, 2);

        // Opening cash: balance of cash accounts (1111, 1112) before fromDate
        $openingCash = $this->getCashBalance($fromDate);
        $closingCash = bcadd($openingCash, $netChange, 2);

        return [
            'operating' => [
                'net_income' => $netIncome,
                'adjustments' => $adjustments,
                'working_capital_changes' => $workingCapitalChanges,
                'total' => $operatingTotal,
            ],
            'investing' => [
                'items' => $investingItems,
                'total' => $investingTotal,
            ],
            'financing' => [
                'items' => $financingItems,
                'total' => $financingTotal,
            ],
            'net_change' => $netChange,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'period' => ['from' => $fromDate, 'to' => $toDate],
        ];
    }

    // ──────────────────────────────────────
    // Comparative Reports
    // ──────────────────────────────────────

    /**
     * Comparative income statement: current vs prior period with variance.
     */
    public function comparativeIncomeStatement(
        string $currentFrom,
        string $currentTo,
        string $priorFrom,
        string $priorTo,
    ): array {
        $current = $this->incomeStatement($currentFrom, $currentTo);
        $prior = $this->incomeStatement($priorFrom, $priorTo);

        return [
            'current' => $current,
            'prior' => $prior,
            'revenue_variance' => $this->calculateVariance($current['revenue']['total'], $prior['revenue']['total']),
            'expenses_variance' => $this->calculateVariance($current['expenses']['total'], $prior['expenses']['total']),
            'net_income_variance' => $this->calculateVariance($current['net_income'], $prior['net_income']),
            'account_variances' => $this->mergeAccountVariances($current, $prior),
        ];
    }

    /**
     * Comparative balance sheet: current vs prior date with variance.
     */
    public function comparativeBalanceSheet(string $currentAsOf, string $priorAsOf): array
    {
        $current = $this->balanceSheet($currentAsOf);
        $prior = $this->balanceSheet($priorAsOf);

        return [
            'current' => $current,
            'prior' => $prior,
            'assets_variance' => $this->calculateVariance($current['assets']['total'], $prior['assets']['total']),
            'liabilities_variance' => $this->calculateVariance($current['liabilities']['total'], $prior['liabilities']['total']),
            'equity_variance' => $this->calculateVariance($current['equity']['total'], $prior['equity']['total']),
        ];
    }

    // ──────────────────────────────────────
    // Private Helpers
    // ──────────────────────────────────────

    /**
     * Get account balances (total debit and credit) for accounts of given types within a date range.
     */
    private function getAccountBalances(array $accountTypes, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $typeValues = array_map(fn (AccountType $t) => $t->value, $accountTypes);

        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('accounts.type', $typeValues);

        if (app('tenant.id')) {
            $query->where('journal_entries.tenant_id', app('tenant.id'));
        }

        if ($fromDate) {
            $query->where('journal_entries.date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->where('journal_entries.date', '<=', $toDate);
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

    /**
     * Get account balances for specific account IDs within a date range.
     */
    private function getAccountBalancesForIds(array $accountIds, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_entry_lines.account_id', $accountIds);

        if (app('tenant.id')) {
            $query->where('journal_entries.tenant_id', app('tenant.id'));
        }

        if ($fromDate) {
            $query->where('journal_entries.date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->where('journal_entries.date', '<=', $toDate);
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

    /**
     * Group accounts under their parent group accounts and calculate subtotals.
     *
     * @param  bool  $isDebitNormal  If true, balance = debit - credit; if false, balance = credit - debit
     */
    private function groupAccountsByParent(Collection $accounts, Collection $balances, bool $isDebitNormal): array
    {
        $grouped = [];

        foreach ($accounts as $account) {
            $bal = $balances->get($account->id);
            $totalDebit = (string) ($bal->total_debit ?? '0');
            $totalCredit = (string) ($bal->total_credit ?? '0');

            $balance = $isDebitNormal
                ? bcsub($totalDebit, $totalCredit, 2)
                : bcsub($totalCredit, $totalDebit, 2);

            // Skip zero-balance accounts
            if (bccomp($balance, '0.00', 2) === 0) {
                continue;
            }

            $parentId = $account->parent_id;

            if (! isset($grouped[$parentId])) {
                // Fetch parent group info
                $parent = $parentId ? Account::find($parentId) : null;
                $grouped[$parentId] = [
                    'group_code' => $parent->code ?? $account->code,
                    'group_name_ar' => $parent->name_ar ?? $account->name_ar,
                    'group_name_en' => $parent->name_en ?? $account->name_en,
                    'accounts' => [],
                    'subtotal' => '0.00',
                ];
            }

            $grouped[$parentId]['accounts'][] = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name_ar' => $account->name_ar,
                'account_name_en' => $account->name_en,
                'balance' => $balance,
            ];

            $grouped[$parentId]['subtotal'] = bcadd($grouped[$parentId]['subtotal'], $balance, 2);
        }

        // Sort by group code and return as indexed array
        $result = array_values($grouped);
        usort($result, fn ($a, $b) => strcmp($a['group_code'], $b['group_code']));

        return $result;
    }

    /**
     * Get cash balance (accounts 1111, 1112) before a given date.
     */
    private function getCashBalance(?string $beforeDate): string
    {
        $cashAccounts = Account::query()
            ->active()
            ->leafAccounts()
            ->whereIn('code', [config('accounting.default_accounts.cash'), config('accounting.default_accounts.bank')])
            ->pluck('id')
            ->toArray();

        if (empty($cashAccounts)) {
            return '0.00';
        }

        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_entry_lines.account_id', $cashAccounts);

        if (app('tenant.id')) {
            $query->where('journal_entries.tenant_id', app('tenant.id'));
        }

        if ($beforeDate) {
            $query->where('journal_entries.date', '<', $beforeDate);
        }

        $result = $query
            ->select(
                DB::raw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            )
            ->first();

        if (! $result) {
            return '0.00';
        }

        // Cash is an asset (debit-normal)
        return bcsub((string) $result->total_debit, (string) $result->total_credit, 2);
    }

    /**
     * Calculate variance between two amounts.
     *
     * @return array{amount: string, percentage: string|null}
     */
    private function calculateVariance(string $current, string $prior): array
    {
        $amount = bcsub($current, $prior, 2);
        $percentage = null;

        if (bccomp($prior, '0.00', 2) !== 0) {
            $percentage = bcmul(bcdiv($amount, $prior, 6), '100', 2);
        }

        return [
            'amount' => $amount,
            'percentage' => $percentage,
        ];
    }

    /**
     * Merge account-level data from two income statement periods with variances.
     */
    private function mergeAccountVariances(array $current, array $prior): array
    {
        $currentMap = [];
        $priorMap = [];

        foreach (['revenue', 'expenses'] as $section) {
            foreach ($current[$section]['groups'] as $group) {
                foreach ($group['accounts'] as $acct) {
                    $currentMap[$acct['account_id']] = $acct['balance'];
                }
            }
            foreach ($prior[$section]['groups'] as $group) {
                foreach ($group['accounts'] as $acct) {
                    $priorMap[$acct['account_id']] = $acct['balance'];
                }
            }
        }

        $allIds = array_unique(array_merge(array_keys($currentMap), array_keys($priorMap)));
        $variances = [];

        foreach ($allIds as $id) {
            $cur = $currentMap[$id] ?? '0.00';
            $pri = $priorMap[$id] ?? '0.00';
            $variances[$id] = $this->calculateVariance($cur, $pri);
        }

        return $variances;
    }
}
