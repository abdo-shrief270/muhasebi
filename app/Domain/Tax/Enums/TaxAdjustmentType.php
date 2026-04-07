<?php

declare(strict_types=1);

namespace App\Domain\Tax\Enums;

enum TaxAdjustmentType: string
{
    case NonDeductibleExpense = 'non_deductible_expense';
    case TaxExemptIncome = 'tax_exempt_income';
    case Depreciation = 'depreciation';
    case LossCarryforward = 'loss_carryforward';
    case TaxCredit = 'tax_credit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NonDeductibleExpense => 'Non-Deductible Expense',
            self::TaxExemptIncome => 'Tax-Exempt Income',
            self::Depreciation => 'Depreciation Adjustment',
            self::LossCarryforward => 'Loss Carryforward',
            self::TaxCredit => 'Tax Credit',
            self::Other => 'Other',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::NonDeductibleExpense => "\u{0645}\u{0635}\u{0631}\u{0648}\u{0641}\u{0627}\u{062a} \u{063a}\u{064a}\u{0631} \u{0642}\u{0627}\u{0628}\u{0644}\u{0629} \u{0644}\u{0644}\u{062e}\u{0635}\u{0645}",
            self::TaxExemptIncome => "\u{062f}\u{062e}\u{0644} \u{0645}\u{0639}\u{0641}\u{0649} \u{0645}\u{0646} \u{0627}\u{0644}\u{0636}\u{0631}\u{064a}\u{0628}\u{0629}",
            self::Depreciation => "\u{062a}\u{0633}\u{0648}\u{064a}\u{0629} \u{0627}\u{0644}\u{0625}\u{0647}\u{0644}\u{0627}\u{0643}",
            self::LossCarryforward => "\u{062e}\u{0633}\u{0627}\u{0626}\u{0631} \u{0645}\u{0631}\u{062d}\u{0644}\u{0629}",
            self::TaxCredit => "\u{0627}\u{0626}\u{062a}\u{0645}\u{0627}\u{0646} \u{0636}\u{0631}\u{064a}\u{0628}\u{064a}",
            self::Other => "\u{0623}\u{062e}\u{0631}\u{0649}",
        };
    }
}
