<?php

declare(strict_types=1);

namespace App\Domain\Tax\Enums;

enum TaxReturnType: string
{
    case CorporateTax = 'corporate_tax';
    case Vat = 'vat';

    public function label(): string
    {
        return match ($this) {
            self::CorporateTax => 'Corporate Tax',
            self::Vat => 'VAT Return',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::CorporateTax => "\u{0636}\u{0631}\u{064a}\u{0628}\u{0629} \u{0627}\u{0644}\u{0634}\u{0631}\u{0643}\u{0627}\u{062a}",
            self::Vat => "\u{0625}\u{0642}\u{0631}\u{0627}\u{0631} \u{0627}\u{0644}\u{0642}\u{064a}\u{0645}\u{0629} \u{0627}\u{0644}\u{0645}\u{0636}\u{0627}\u{0641}\u{0629}",
        };
    }
}
