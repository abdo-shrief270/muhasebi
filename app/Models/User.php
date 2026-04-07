<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'tenant_id',
    'client_id',
    'name',
    'email',
    'password',
    'phone',
    'role',
    'locale',
    'timezone',
    'ui_preferences',
    'is_active',
    'last_login_at',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'two_factor_enabled',
    'password_changed_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;
    use LogsActivity;

    /**
     * Super admins are NOT tenant-scoped.
     * We apply BelongsToTenant manually for tenant-level users only.
     */
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'client',
        'locale' => 'ar',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'password_changed_at' => 'datetime',
            'ui_preferences' => 'array',
        ];
    }

        // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Client\Models\Client::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(\App\Domain\Payroll\Models\Employee::class);
    }

    public function timesheetEntries(): HasMany
    {
        return $this->hasMany(\App\Domain\TimeTracking\Models\TimesheetEntry::class);
    }

    public function timers(): HasMany
    {
        return $this->hasMany(\App\Domain\TimeTracking\Models\Timer::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSuperAdmins(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->withoutGlobalScope('tenant')->where('role', UserRole::SuperAdmin);
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    public function isTenantUser(): bool
    {
        return $this->role->isTenantLevel();
    }

    public function recordLogin(): void
    {
        $this->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'role', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }


    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
