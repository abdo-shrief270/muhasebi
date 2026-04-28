<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Per-vendor billable item — speeds up bill entry by pre-filling description,
 * unit price, VAT rate, and (uniquely on the AP side) the GL account. See
 * the migration for the rationale on why this is separate from
 * inventory.products.
 *
 * Tenant scoping is enforced by BelongsToTenant. The unique
 * (tenant_id, vendor_id, name) index in the migration is caught at the
 * controller layer and surfaced as a 422.
 */
#[Table('vendor_products')]
#[Fillable([
    'tenant_id',
    'vendor_id',
    'name',
    'description',
    'unit_price',
    'default_vat_rate',
    'default_account_id',
    'is_active',
    'last_used_at',
])]
class VendorProduct extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'default_vat_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_account_id');
    }

    /** Active items only — used by the bill-line picker. */
    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    /**
     * Recently-used items first so frequent purchases surface at the top of
     * the picker. Falls back to alphabetical for items never picked yet.
     */
    public function scopeRecentFirst(Builder $q): void
    {
        $q->orderByDesc('last_used_at')->orderBy('name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'unit_price', 'default_vat_rate', 'default_account_id', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
