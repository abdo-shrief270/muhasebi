<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

class EgyptianTaxService
{
    // 2025 Egyptian social insurance rates
    private const SI_EMPLOYEE_RATE = 0.11;
    private const SI_EMPLOYER_RATE = 0.1875;
    private const SI_MAX_BASIC_SALARY = 12600.00;

    // Annual personal exemption
    private const PERSONAL_EXEMPTION = 12000.00;

    /**
     * Calculate social insurance shares.
     *
     * @return array{employee_share: float, employer_share: float}
     */
    public static function calculateSocialInsurance(float $baseSalary, bool $isInsured): array
    {
        if (! $isInsured) {
            return ['employee_share' => 0.0, 'employer_share' => 0.0];
        }

        $cappedSalary = min($baseSalary, self::SI_MAX_BASIC_SALARY);

        return [
            'employee_share' => round($cappedSalary * self::SI_EMPLOYEE_RATE, 2),
            'employer_share' => round($cappedSalary * self::SI_EMPLOYER_RATE, 2),
        ];
    }

    /**
     * Calculate annual income tax using Egyptian progressive brackets.
     *
     * Brackets (2023+):
     *   0       – 40,000  => 0%
     *   40,001  – 55,000  => 10%
     *   55,001  – 70,000  => 15%
     *   70,001  – 200,000 => 20%
     *   200,001 – 400,000 => 22.5%
     *   400,001 – 600,000 => 25%
     *   600,001+          => 27.5%
     */
    public static function calculateIncomeTax(float $annualTaxableIncome): float
    {
        if ($annualTaxableIncome <= 0) {
            return 0.0;
        }

        $brackets = [
            [40000, 0.00],
            [15000, 0.10],   // 40,001 – 55,000
            [15000, 0.15],   // 55,001 – 70,000
            [130000, 0.20],  // 70,001 – 200,000
            [200000, 0.225], // 200,001 – 400,000
            [200000, 0.25],  // 400,001 – 600,000
            [PHP_FLOAT_MAX, 0.275], // 600,001+
        ];

        $tax = 0.0;
        $remaining = $annualTaxableIncome;

        foreach ($brackets as [$width, $rate]) {
            if ($remaining <= 0) {
                break;
            }

            $taxable = min($remaining, $width);
            $tax += $taxable * $rate;
            $remaining -= $taxable;
        }

        return round($tax, 2);
    }

    /**
     * Annual personal exemption per Egyptian law.
     */
    public static function personalExemption(): float
    {
        return self::PERSONAL_EXEMPTION;
    }
}
