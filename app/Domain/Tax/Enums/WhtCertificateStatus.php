<?php

declare(strict_types=1);

namespace App\Domain\Tax\Enums;

enum WhtCertificateStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Submitted => 'Submitted to Tax Authority',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => "\u{0645}\u{0633}\u{0648}\u{062f}\u{0629}",
            self::Issued => "\u{0635}\u{0627}\u{062f}\u{0631}\u{0629}",
            self::Submitted => "\u{0645}\u{0642}\u{062f}\u{0645}\u{0629} \u{0644}\u{0644}\u{0636}\u{0631}\u{0627}\u{0626}\u{0628}",
        };
    }
}
