<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Models;

use App\Domain\Shared\Enums\TenantStatus;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('tenants')]
#[Fillable([
    'name',
    'slug',
    'domain',
    'email',
    'phone',
    'tax_id',
    'commercial_register',
    'address',
    'city',
    'status',
    'settings',
    'trial_ends_at',
    'logo_path',
    'tagline',
    'description',
    'primary_color',
    'secondary_color',
    'hero_image_path',
    'is_landing_page_active',
    'custom_domain',
    'favicon_path',
    'social_links',
    'custom_css',
])]

class Tenant extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'trial',
        'settings' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'is_landing_page_active' => 'boolean',
            'social_links' => 'array',
            'custom_css' => 'array',
        ];
    }
    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TenantStatus::Active);
    }

    public function scopeAccessible(Builder $query): Builder
    {
        return $query->whereIn('status', [TenantStatus::Active, TenantStatus::Trial]);
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function isAccessible(): bool
    {
        return $this->status->isAccessible();
    }

    public function isOnTrial(): bool
    {
        return $this->status === TenantStatus::Trial
            && $this->trial_ends_at?->isFuture();
    }

    public function hasActiveLandingPage(): bool
    {
        return $this->is_landing_page_active && $this->isAccessible();
    }

    public function hasExpiredTrial(): bool
    {
        return $this->status === TenantStatus::Trial
            && $this->trial_ends_at?->isPast();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'status', 'settings'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
