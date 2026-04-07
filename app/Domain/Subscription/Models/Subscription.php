<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Subscription\Enums\PaymentGateway;
use App\Domain\Subscription\Enums\SubscriptionStatus;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('subscriptions')]
#[Fillable([
    'tenant_id',
    'plan_id',
    'status',
    'billing_cycle',
    'price',
    'currency',
    'trial_ends_at',
    'current_period_start',
    'current_period_end',
    'cancelled_at',
    'cancellation_reason',
    'expires_at',
    'gateway',
    'gateway_subscription_id',
    'metadata',
])]
class Subscription extends Model
{
    use HasFactory;
    use BelongsToTenant;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'gateway' => PaymentGateway::class,
            'price' => 'decimal:2',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'trial',
        'billing_cycle' => 'monthly',
        'currency' => 'EGP',
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [SubscriptionStatus::Trial, SubscriptionStatus::Active]);
    }

    public function scopeOfStatus(Builder $query, SubscriptionStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trial;
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled;
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Expired;
    }

    public function isAccessible(): bool
    {
        return $this->status->isAccessible();
    }

    public function onTrial(): bool
    {
        return $this->isTrialing() && $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function trialDaysRemaining(): int
    {
        if ($this->trial_ends_at === null) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->trial_ends_at, absolute: false));
    }

    public function daysUntilRenewal(): int
    {
        if ($this->current_period_end === null) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->current_period_end, absolute: false));
    }

    public function hasExpiredTrial(): bool
    {
        return $this->isTrialing() && $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'plan_id', 'price', 'billing_cycle'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
