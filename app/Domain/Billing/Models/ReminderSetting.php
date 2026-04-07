<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('reminder_settings')]
#[Fillable([
    'tenant_id',
    'milestones',
    'channels',
    'is_enabled',
    'send_to_contact_person',
    'escalation_email',
])]
class ReminderSetting extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'milestones' => 'array',
            'channels' => 'array',
            'is_enabled' => 'boolean',
            'send_to_contact_person' => 'boolean',
        ];
    }

    /**
     * Get or create settings for the current tenant.
     */
    public static function forCurrentTenant(): self
    {
        return static::firstOrCreate(
            ['tenant_id' => (int) app('tenant.id')],
            [
                'milestones' => [30, 60, 90],
                'channels' => ['email'],
                'is_enabled' => true,
            ],
        );
    }
}
