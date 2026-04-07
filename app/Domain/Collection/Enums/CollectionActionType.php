<?php

declare(strict_types=1);

namespace App\Domain\Collection\Enums;

enum CollectionActionType: string
{
    case PhoneCall = 'phone_call';
    case Email = 'email';
    case Letter = 'letter';
    case Visit = 'visit';
    case WriteOff = 'write_off';
    case Escalation = 'escalation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PhoneCall => 'Phone Call',
            self::Email => 'Email',
            self::Letter => 'Letter',
            self::Visit => 'Visit',
            self::WriteOff => 'Write-Off',
            self::Escalation => 'Escalation',
            self::Other => 'Other',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::PhoneCall => 'مكالمة هاتفية',
            self::Email => 'بريد إلكتروني',
            self::Letter => 'خطاب',
            self::Visit => 'زيارة',
            self::WriteOff => 'شطب',
            self::Escalation => 'تصعيد',
            self::Other => 'أخرى',
        };
    }
}
