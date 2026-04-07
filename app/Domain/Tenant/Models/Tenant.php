<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Models;

use App\Domain\Shared\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

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
    use SoftDeletes;
    use LogsActivity;

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
        return $this->hasMany(\App\Models\User::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', TenantStatus::Active);
    }

    public function scopeAccessible(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
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
