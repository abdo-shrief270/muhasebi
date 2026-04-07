<?php

declare(strict_types=1);

namespace App\Domain\Client\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('clients')]
#[Fillable([
    'tenant_id',
    'name',
    'trade_name',
    'tax_id',
    'commercial_register',
    'activity_type',
    'address',
    'city',
    'phone',
    'email',
    'contact_person',
    'contact_phone',
    'notes',
    'is_active',
])]
class Client extends Model
{
    use HasFactory;
    use SoftDeletes;
    use BelongsToTenant;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function portalUsers(): HasMany
    {
        return $this->hasMany(\App\Models\User::class, 'client_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(\App\Domain\ClientPortal\Models\Message::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(\Illuminate\Database\Eloquent\Builder $query, ?string $term): \Illuminate\Database\Eloquent\Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($term): void {
            $q->where('name', 'ilike', "%{$term}%")
                ->orWhere('trade_name', 'ilike', "%{$term}%")
                ->orWhere('tax_id', 'ilike', "%{$term}%")
                ->orWhere('commercial_register', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")
                ->orWhere('contact_person', 'ilike', "%{$term}%");
        });
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'tax_id', 'is_active', 'contact_person'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
