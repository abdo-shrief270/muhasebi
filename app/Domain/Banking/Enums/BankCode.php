<?php

declare(strict_types=1);

namespace App\Domain\Banking\Enums;

enum BankCode: string
{
    case NBE = 'nbe';
    case CIB = 'cib';
    case BanqueDuCaire = 'banque_du_caire';
    case AAIB = 'aaib';
    case QNB = 'qnb';
    case HSBC = 'hsbc';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NBE => 'National Bank of Egypt',
            self::CIB => 'Commercial International Bank',
            self::BanqueDuCaire => 'Banque du Caire',
            self::AAIB => 'Arab African International Bank',
            self::QNB => 'Qatar National Bank Alahli',
            self::HSBC => 'HSBC Egypt',
            self::Other => 'Other',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::NBE => 'البنك الأهلي المصري',
            self::CIB => 'البنك التجاري الدولي',
            self::BanqueDuCaire => 'بنك القاهرة',
            self::AAIB => 'البنك العربي الأفريقي الدولي',
            self::QNB => 'بنك قطر الوطني الأهلي',
            self::HSBC => 'إتش إس بي سي مصر',
            self::Other => 'أخرى',
        };
    }

    /**
     * Whether this bank supports direct API integration.
     * Placeholder — all return false until Egyptian bank APIs become available.
     */
    public function supportsApi(): bool
    {
        return false;
    }
}
