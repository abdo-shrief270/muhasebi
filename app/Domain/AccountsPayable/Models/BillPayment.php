<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('bill_payments')]
#[Fillable([
    'tenant_id',
    'bill_id',
    'vendor_id',
    'amount',
    'payment_date',
    'payment_method',
    'reference',
    'notes',
    'journal_entry_id',
    'created_by',
])]
class BillPayment extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForBill(Builder $query, int $billId): Builder
    {
        return $query->where('bill_id', $billId);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeDateRange(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('payment_date', [$from, $to]);
    }

    public function scopeOfMethod(Builder $query, PaymentMethod $method): Builder
    {
        return $query->where('payment_method', $method);
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'payment_method', 'reference'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
