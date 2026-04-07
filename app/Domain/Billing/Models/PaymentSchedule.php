<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('payment_schedules')]
#[Fillable([
    'tenant_id',
    'bill_id',
    'invoice_id',
    'scheduled_date',
    'amount',
    'status',
    'payment_method',
    'early_discount_percent',
    'early_discount_deadline',
    'early_discount_amount',
    'approved_by',
    'processed_at',
    'notes',
    'created_by',
])]
class PaymentSchedule extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'early_discount_percent' => 0,
        'early_discount_amount' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'early_discount_deadline' => 'date',
            'amount' => 'decimal:2',
            'early_discount_percent' => 'decimal:2',
            'early_discount_amount' => 'decimal:2',
            'processed_at' => 'datetime',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOfStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeScheduledBefore(Builder $query, string $date): Builder
    {
        return $query->where('scheduled_date', '<=', $date);
    }

    public function scopeForBill(Builder $query, int $billId): Builder
    {
        return $query->where('bill_id', $billId);
    }

    public function scopeForInvoice(Builder $query, int $invoiceId): Builder
    {
        return $query->where('invoice_id', $invoiceId);
    }
}
