<?php

declare(strict_types=1);

namespace App\Domain\ECommerce\Models;

use App\Domain\ECommerce\Enums\ECommercePlatform;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('ecommerce_channels')]
#[Fillable([
    'tenant_id',
    'platform',
    'name',
    'api_url',
    'api_key',
    'api_secret',
    'webhook_secret',
    'is_active',
    'last_sync_at',
    'sync_status',
    'settings',
    'created_by',
])]
class ECommerceChannel extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform' => ECommercePlatform::class,
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_sync_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'sync_status' => 'idle',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['platform', 'name', 'is_active', 'sync_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "E-commerce channel {$eventName}");
    }

    // ── Relationships ──

    public function orders(): HasMany
    {
        return $this->hasMany(ECommerceOrder::class, 'channel_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
