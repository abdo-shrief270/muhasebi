<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Currency\Models\ExchangeRate;

/**
 * Converts financial report data from the base currency (EGP) to a target
 * reporting currency using exchange rates at the reporting date.
 */
class ReportCurrencyConverter
{
    private const BASE_CURRENCY = 'EGP';

    /**
     * Convert a complete income statement to the target currency.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function convertIncomeStatement(array $data, string $targetCurrency): array
    {
        $date = $data['period']['to'] ?? date('Y-m-d');
        $rate = $this->getRate($targetCurrency, $date);

        if ($rate === null) {
            $data['currency'] = self::BASE_CURRENCY;
            $data['conversion_error'] = "No exchange rate available for {$targetCurrency}";

            return $data;
        }

        $data['revenue']['groups'] = $this->convertGroups($data['revenue']['groups'], $rate);
        $data['revenue']['total'] = $this->convertAmount($data['revenue']['total'], $rate);
        $data['expenses']['groups'] = $this->convertGroups($data['expenses']['groups'], $rate);
        $data['expenses']['total'] = $this->convertAmount($data['expenses']['total'], $rate);
        $data['net_income'] = $this->convertAmount($data['net_income'], $rate);
        $data['currency'] = $targetCurrency;
        $data['exchange_rate'] = $rate;

        return $data;
    }

    /**
     * Convert a complete balance sheet to the target currency.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function convertBalanceSheet(array $data, string $targetCurrency): array
    {
        $date = $data['as_of_date'] ?? date('Y-m-d');
        $rate = $this->getRate($targetCurrency, $date);

        if ($rate === null) {
            $data['currency'] = self::BASE_CURRENCY;
            $data['conversion_error'] = "No exchange rate available for {$targetCurrency}";

            return $data;
        }

        $data['assets']['groups'] = $this->convertGroups($data['assets']['groups'], $rate);
        $data['assets']['total'] = $this->convertAmount($data['assets']['total'], $rate);
        $data['liabilities']['groups'] = $this->convertGroups($data['liabilities']['groups'], $rate);
        $data['liabilities']['total'] = $this->convertAmount($data['liabilities']['total'], $rate);
        $data['equity']['groups'] = $this->convertGroups($data['equity']['groups'], $rate);
        $data['equity']['net_income'] = $this->convertAmount($data['equity']['net_income'], $rate);
        $data['equity']['total'] = $this->convertAmount($data['equity']['total'], $rate);
        $data['total_liabilities_and_equity'] = $this->convertAmount($data['total_liabilities_and_equity'], $rate);
        $data['currency'] = $targetCurrency;
        $data['exchange_rate'] = $rate;

        return $data;
    }

    /**
     * Convert a cash flow statement to the target currency.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function convertCashFlow(array $data, string $targetCurrency): array
    {
        $date = $data['period']['to'] ?? date('Y-m-d');
        $rate = $this->getRate($targetCurrency, $date);

        if ($rate === null) {
            $data['currency'] = self::BASE_CURRENCY;
            $data['conversion_error'] = "No exchange rate available for {$targetCurrency}";

            return $data;
        }

        foreach (['operating', 'investing', 'financing'] as $section) {
            if (isset($data[$section]['items'])) {
                $data[$section]['items'] = array_map(
                    fn ($item) => array_merge($item, ['amount' => $this->convertAmount($item['amount'], $rate)]),
                    $data[$section]['items'],
                );
            }

            if (isset($data[$section]['total'])) {
                $data[$section]['total'] = $this->convertAmount($data[$section]['total'], $rate);
            }
        }

        $data['net_change'] = $this->convertAmount($data['net_change'] ?? '0.00', $rate);
        $data['opening_cash'] = $this->convertAmount($data['opening_cash'] ?? '0.00', $rate);
        $data['closing_cash'] = $this->convertAmount($data['closing_cash'] ?? '0.00', $rate);
        $data['currency'] = $targetCurrency;
        $data['exchange_rate'] = $rate;

        return $data;
    }

    /**
     * Convert a trial balance to the target currency.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function convertTrialBalance(array $data, string $targetCurrency, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $rate = $this->getRate($targetCurrency, $date);

        if ($rate === null) {
            $data['currency'] = self::BASE_CURRENCY;
            $data['conversion_error'] = "No exchange rate available for {$targetCurrency}";

            return $data;
        }

        $data['rows'] = array_map(function ($row) use ($rate) {
            $row['opening_debit'] = $this->convertAmount($row['opening_debit'], $rate);
            $row['opening_credit'] = $this->convertAmount($row['opening_credit'], $rate);
            $row['period_debit'] = $this->convertAmount($row['period_debit'], $rate);
            $row['period_credit'] = $this->convertAmount($row['period_credit'], $rate);
            $row['closing_debit'] = $this->convertAmount($row['closing_debit'], $rate);
            $row['closing_credit'] = $this->convertAmount($row['closing_credit'], $rate);

            return $row;
        }, $data['rows']);

        foreach ($data['totals'] as $key => $value) {
            $data['totals'][$key] = $this->convertAmount($value, $rate);
        }

        $data['currency'] = $targetCurrency;
        $data['exchange_rate'] = $rate;

        return $data;
    }

    /**
     * Get exchange rate from base currency to target.
     */
    private function getRate(string $targetCurrency, string $date): ?float
    {
        if ($targetCurrency === self::BASE_CURRENCY) {
            return 1.0;
        }

        return ExchangeRate::getRate(self::BASE_CURRENCY, $targetCurrency, $date);
    }

    /**
     * Convert a single amount string using the given rate.
     */
    private function convertAmount(string $amount, float $rate): string
    {
        return number_format(round((float) $amount * $rate, 2), 2, '.', '');
    }

    /**
     * Convert account groups (used in income statement and balance sheet).
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function convertGroups(array $groups, float $rate): array
    {
        return array_map(function ($group) use ($rate) {
            $group['accounts'] = array_map(
                fn ($account) => array_merge($account, ['balance' => $this->convertAmount($account['balance'], $rate)]),
                $group['accounts'],
            );
            $group['subtotal'] = $this->convertAmount($group['subtotal'], $rate);

            return $group;
        }, $groups);
    }
}
