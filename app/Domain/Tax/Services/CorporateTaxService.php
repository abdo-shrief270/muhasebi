<?php

declare(strict_types=1);

namespace App\Domain\Tax\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Tax\Enums\TaxAdjustmentType;
use App\Domain\Tax\Enums\TaxReturnStatus;
use App\Domain\Tax\Enums\TaxReturnType;
use App\Domain\Tax\Models\TaxAdjustment;
use App\Domain\Tax\Models\TaxReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CorporateTaxService
{
    /**
     * Egyptian standard corporate tax rate: 22.5%.
     */
    private const string CORPORATE_TAX_RATE = '0.225';

    /**
     * Calculate corporate tax return for a fiscal year.
     *
     * Steps (all bcmath):
     * 1. Get gross revenue from income statement (revenue accounts)
     * 2. Get total expenses from income statement (expense accounts)
     * 3. Calculate accounting profit: revenue - expenses
     * 4. Apply tax adjustments (additions and subtractions)
     * 5. Calculate taxable income: accounting_profit + additions - subtractions
     * 6. Apply Egyptian corporate tax rate (22.5%)
     * 7. Deduct any tax loss carryforward
     * 8. Create/update TaxReturn record with breakdown
     * 9. Return the tax return
     */
    public function calculate(int $fiscalYearId): TaxReturn
    {
        $fiscalYear = FiscalYear::findOrFail($fiscalYearId);
        $fromDate = $fiscalYear->start_date->format('Y-m-d');
        $toDate = $fiscalYear->end_date->format('Y-m-d');

        // 1. Gross revenue (credit - debit for revenue accounts)
        $grossRevenue = $this->getAccountTypeBalance(
            AccountType::Revenue,
            $fromDate,
            $toDate,
            false,
        );

        // 2. Total expenses (debit - credit for expense accounts)
        $totalExpenses = $this->getAccountTypeBalance(
            AccountType::Expense,
            $fromDate,
            $toDate,
            true,
        );

        // 3. Accounting profit
        $accountingProfit = bcsub($grossRevenue, $totalExpenses, 2);

        // 4. Tax adjustments
        $adjustments = TaxAdjustment::query()
            ->forFiscalYear($fiscalYearId)
            ->get();

        $additionAdjustments = '0.00';
        $subtractionAdjustments = '0.00';

        /** @var Collection<int, array<string, mixed>> $adjustmentDetails */
        $adjustmentDetails = [];

        foreach ($adjustments as $adjustment) {
            $amount = (string) $adjustment->amount;

            if ($adjustment->type === TaxAdjustmentType::Addition) {
                $additionAdjustments = bcadd($additionAdjustments, $amount, 2);
            } else {
                $subtractionAdjustments = bcadd($subtractionAdjustments, $amount, 2);
            }

            $adjustmentDetails[] = [
                'id' => $adjustment->id,
                'type' => $adjustment->type->value,
                'description_ar' => $adjustment->description_ar,
                'description_en' => $adjustment->description_en,
                'amount' => $amount,
            ];
        }

        // 5. Taxable income
        $taxableIncome = bcadd(
            bcsub($accountingProfit, $subtractionAdjustments, 2),
            $additionAdjustments,
            2,
        );

        // Taxable income cannot be negative for tax calculation
        $taxableIncomeForTax = bccomp($taxableIncome, '0', 2) > 0
            ? $taxableIncome
            : '0.00';

        // 6. Corporate tax at 22.5%
        $grossTax = bcmul($taxableIncomeForTax, self::CORPORATE_TAX_RATE, 2);

        // 7. Tax loss carryforward (from prior year returns with negative taxable income)
        $lossCarryforward = $this->getTaxLossCarryforward($fiscalYear);
        $netTaxDue = bcsub($grossTax, $lossCarryforward, 2);

        // Net tax cannot be negative
        if (bccomp($netTaxDue, '0', 2) < 0) {
            $netTaxDue = '0.00';
        }

        // 8. Create or update TaxReturn
        $data = [
            'gross_revenue' => $grossRevenue,
            'total_expenses' => $totalExpenses,
            'accounting_profit' => $accountingProfit,
            'addition_adjustments' => $additionAdjustments,
            'subtraction_adjustments' => $subtractionAdjustments,
            'adjustments' => $adjustmentDetails,
            'taxable_income' => $taxableIncome,
            'tax_rate' => self::CORPORATE_TAX_RATE,
            'gross_tax' => $grossTax,
            'loss_carryforward' => $lossCarryforward,
            'net_tax_due' => $netTaxDue,
            'calculated_at' => now()->format('Y-m-d H:i'),
            'currency' => 'EGP',
        ];

        $taxReturn = TaxReturn::updateOrCreate(
            [
                'tenant_id' => app('tenant.id'),
                'fiscal_year_id' => $fiscalYearId,
                'type' => TaxReturnType::CorporateTax,
            ],
            [
                'period_from' => $fromDate,
                'period_to' => $toDate,
                'status' => TaxReturnStatus::Calculated,
                'tax_due' => $netTaxDue,
                'balance' => $netTaxDue,
                'data' => $data,
            ],
        );

        // 9. Return
        return $taxReturn->refresh();
    }

    /**
     * Create a tax adjustment record.
     *
     * @param  array<string, mixed>  $data
     */
    public function addAdjustment(array $data): TaxAdjustment
    {
        return TaxAdjustment::create([
            'fiscal_year_id' => $data['fiscal_year_id'],
            'type' => $data['type'],
            'description_ar' => $data['description_ar'],
            'description_en' => $data['description_en'],
            'amount' => $data['amount'],
            'reference' => $data['reference'] ?? null,
        ]);
    }

    /**
     * List adjustments for a fiscal year.
     *
     * @return Collection<int, TaxAdjustment>
     */
    public function listAdjustments(int $fiscalYearId): Collection
    {
        return TaxAdjustment::query()
            ->forFiscalYear($fiscalYearId)
            ->orderBy('type')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Delete an adjustment if its return has not been filed.
     */
    public function deleteAdjustment(TaxAdjustment $adj): bool
    {
        // Check if there's a filed return for this fiscal year
        $filedReturn = TaxReturn::query()
            ->forFiscalYear($adj->fiscal_year_id)
            ->ofType(TaxReturnType::CorporateTax)
            ->whereIn('status', [TaxReturnStatus::Filed, TaxReturnStatus::Paid])
            ->exists();

        if ($filedReturn) {
            throw new \DomainException('Cannot delete adjustment for a filed tax return.');
        }

        return (bool) $adj->delete();
    }

    /**
     * Mark a tax return as filed.
     */
    public function file(TaxReturn $return): TaxReturn
    {
        $return->update([
            'status' => TaxReturnStatus::Filed,
            'filed_at' => now(),
        ]);

        return $return->refresh();
    }

    /**
     * Record a tax payment and update balance.
     */
    public function recordPayment(TaxReturn $return, string $amount): TaxReturn
    {
        $newPaid = bcadd((string) $return->tax_paid, $amount, 2);
        $newBalance = bcsub((string) $return->tax_due, $newPaid, 2);

        $status = $return->status;

        if (bccomp($newBalance, '0', 2) <= 0) {
            $status = TaxReturnStatus::Paid;
            $newBalance = '0.00';
        }

        $return->update([
            'tax_paid' => $newPaid,
            'balance' => $newBalance,
            'status' => $status,
        ]);

        return $return->refresh();
    }

    /**
     * Get the net balance for all accounts of a given type within a date range.
     *
     * @param  bool  $isDebitNormal  true for debit-normal (expenses), false for credit-normal (revenue)
     */
    private function getAccountTypeBalance(
        AccountType $type,
        string $fromDate,
        string $toDate,
        bool $isDebitNormal,
    ): string {
        $tenantId = (int) app('tenant.id');

        $result = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereNull('journal_entries.deleted_at')
            ->where('accounts.type', $type->value)
            ->where('accounts.is_group', false)
            ->whereBetween('journal_entries.date', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            ->first();

        if (! $result) {
            return '0.00';
        }

        $totalDebit = (string) $result->total_debit;
        $totalCredit = (string) $result->total_credit;

        return $isDebitNormal
            ? bcsub($totalDebit, $totalCredit, 2)
            : bcsub($totalCredit, $totalDebit, 2);
    }

    /**
     * Get tax loss carryforward from prior fiscal years.
     * Looks for corporate tax returns with negative taxable income that haven't been fully utilized.
     */
    private function getTaxLossCarryforward(FiscalYear $currentYear): string
    {
        $priorReturns = TaxReturn::query()
            ->ofType(TaxReturnType::CorporateTax)
            ->where('period_to', '<', $currentYear->start_date->format('Y-m-d'))
            ->whereIn('status', [TaxReturnStatus::Calculated, TaxReturnStatus::Filed, TaxReturnStatus::Paid])
            ->orderBy('period_from')
            ->get();

        $totalLoss = '0.00';

        foreach ($priorReturns as $priorReturn) {
            $data = $priorReturn->data;

            if (! $data || ! isset($data['taxable_income'])) {
                continue;
            }

            $taxableIncome = (string) $data['taxable_income'];

            // Only carry forward losses (negative taxable income)
            if (bccomp($taxableIncome, '0', 2) < 0) {
                $loss = bcmul($taxableIncome, '-1', 2);
                $totalLoss = bcadd($totalLoss, $loss, 2);
            }
        }

        return $totalLoss;
    }
}
