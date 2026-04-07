<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Models;

use App\Domain\Billing\Models\Payment;
use App\Domain\ClientPortal\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('payment_plan_installments')]
#[Fillable([
    'payment_plan_id',
    'due_date',
    'amount',
    'status',
    'paid_at',
    'payment_id',
])]
class PaymentPlanInstallment extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => InstallmentStatus::class,
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class, 'payment_plan_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function isPaid(): bool
    {
        return $this->status === InstallmentStatus::Paid;
    }

    public function isPending(): bool
    {
        return $this->status === InstallmentStatus::Pending;
    }
}
