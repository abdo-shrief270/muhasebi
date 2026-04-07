<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\AccountsPayable\Enums\BillStatus;
use App\Domain\AccountsPayable\Enums\BillType;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('bills')]
#[Fillable([
    'tenant_id',
    'vendor_id',
    'type',
    'bill_number',
    'vendor_invoice_number',
    'date',
    'due_date',
    'status',
    'subtotal',
    'discount_amount',
    'vat_amount',
    'wht_amount',
    'total',
    'amount_paid',
    'currency',
    'notes',
    'terms',
    'approved_at',
    'approved_by',
    'cancelled_at',
    'cancelled_by',
    'journal_entry_id',
    'created_by',
])]
class Bill extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'type' => BillType::class,
            'status' => BillStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'wht_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'bill',
        'status' => 'draft',
        'subtotal' => 0,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'wht_amount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'currency' => 'EGP',
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === BillStatus::Draft;
    }

    public function isPaid(): bool
    {
        return $this->status === BillStatus::Paid;
    }

    public function isOverdue(): bool
    {
        if (in_array($this->status, [BillStatus::Paid, BillStatus::Cancelled], true)) {
            return false;
        }

        return $this->due_date !== null && $this->due_date->isPast();
    }

    public function balanceDue(): float
    {
        return (float) bcsub((string) $this->total, (string) $this->amount_paid, 2);
    }

    public function isFullyPaid(): bool
    {
        return bccomp((string) $this->amount_paid, (string) $this->total, 2) >= 0;
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOfStatus(Builder $query, BillStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeOfType(Builder $query, BillType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', today())
            ->whereIn('status', [BillStatus::Approved, BillStatus::PartiallyPaid]);
    }

    public function scopeDateRange(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('bill_number', 'ilike', "%{$term}%")
                ->orWhere('vendor_invoice_number', 'ilike', "%{$term}%")
                ->orWhere('notes', 'ilike', "%{$term}%")
                ->orWhereHas('vendor', function (Builder $vendorQuery) use ($term): void {
                    $vendorQuery->where('name_ar', 'ilike', "%{$term}%")
                        ->orWhere('name_en', 'ilike', "%{$term}%");
                });
        });
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bill_number', 'status', 'total', 'amount_paid'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
