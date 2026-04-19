<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\AccountsPayable\Enums\BillStatus;
use App\Domain\Client\Models\Client;
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

#[Table('bills')]
#[Fillable([
    'tenant_id',
    'vendor_id',
    'bill_number',
    'date',
    'due_date',
    'status',
    'subtotal',
    'vat_amount',
    'wht_amount',
    'total',
    'amount_paid',
    'currency',
    'notes',
    'cancelled_at',
    'cancelled_by',
    'journal_entry_id',
    'created_by',
])]
class Bill extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'status' => BillStatus::class,
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'wht_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'subtotal' => 0,
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
        return $this->belongsTo(Client::class, 'vendor_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function isApproved(): bool
    {
        return $this->status === BillStatus::Approved;
    }

    public function balanceDue(): float
    {
        return (float) bcsub((string) $this->total, (string) $this->amount_paid, 2);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOfStatus(Builder $query, BillStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('bill_number', 'ilike', "%{$term}%")
                ->orWhere('notes', 'ilike', "%{$term}%");
        });
    }
}
