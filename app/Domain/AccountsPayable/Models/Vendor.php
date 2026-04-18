<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('vendors')]
#[Fillable([
    'tenant_id',
    'name_ar',
    'name_en',
    'code',
    'tax_id',
    'commercial_register',
    'vat_registration',
    'email',
    'phone',
    'address_ar',
    'address_en',
    'city',
    'country',
    'bank_name',
    'bank_account',
    'iban',
    'swift_code',
    'payment_terms',
    'credit_limit',
    'currency',
    'contacts',
    'notes',
    'is_active',
    'created_by',
])]
class Vendor extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contacts' => 'array',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'currency' => 'EGP',
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function balanceDue(): string
    {
        $totalBills = $this->bills()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum('total');
        $totalPaid = $this->bills()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum('amount_paid');

        return bcsub((string) $totalBills, (string) $totalPaid, 2);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name_ar', 'ilike', "%{$term}%")
                ->orWhere('name_en', 'ilike', "%{$term}%")
                ->orWhere('code', 'ilike', "%{$term}%")
                ->orWhere('tax_id', 'ilike', "%{$term}%");
        });
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name_ar', 'name_en', 'code', 'tax_id', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
