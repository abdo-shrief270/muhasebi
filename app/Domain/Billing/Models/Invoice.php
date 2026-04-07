<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Client\Models\Client;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('invoices')]
#[Fillable([
    'tenant_id',
    'client_id',
    'type',
    'invoice_number',
    'date',
    'due_date',
    'status',
    'subtotal',
    'discount_amount',
    'vat_amount',
    'total',
    'amount_paid',
    'currency',
    'notes',
    'terms',
    'sent_at',
    'cancelled_at',
    'cancelled_by',
    'original_invoice_id',
    'journal_entry_id',
    'created_by',
])]
class Invoice extends Model
{
    use HasFactory;
    use SoftDeletes;
    use BelongsToTenant;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'invoice',
        'status' => 'draft',
        'subtotal' => 0,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'currency' => 'EGP',
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
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
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id')
            ->where('type', InvoiceType::CreditNote);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function etaDocument(): HasOne
    {
        return $this->hasOne(\App\Domain\EInvoice\Models\EtaDocument::class);
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    public function isOverdue(): bool
    {
        if (in_array($this->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
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

    public function scopeOfStatus(Builder $query, InvoiceStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeOfType(Builder $query, InvoiceType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', today())
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid]);
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
            $q->where('invoice_number', 'ilike', "%{$term}%")
                ->orWhere('notes', 'ilike', "%{$term}%")
                ->orWhereHas('client', function (Builder $clientQuery) use ($term): void {
                    $clientQuery->where('name', 'ilike', "%{$term}%");
                });
        });
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_number', 'status', 'total', 'amount_paid'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
