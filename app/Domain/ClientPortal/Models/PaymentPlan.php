<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;
use App\Domain\ClientPortal\Enums\PaymentPlanFrequency;
use App\Domain\ClientPortal\Enums\PaymentPlanStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('payment_plans')]
#[Fillable([
    'tenant_id',
    'invoice_id',
    'client_id',
    'total_amount',
    'installments_count',
    'installment_amount',
    'frequency',
    'start_date',
    'status',
    'next_due_date',
    'paid_installments',
    'remaining_amount',
    'notes',
    'created_by',
])]
class PaymentPlan extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'frequency' => PaymentPlanFrequency::class,
            'status' => PaymentPlanStatus::class,
            'start_date' => 'date',
            'next_due_date' => 'date',
            'installments_count' => 'integer',
            'paid_installments' => 'integer',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstallment::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PaymentPlanStatus::Active);
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === PaymentPlanStatus::Completed;
    }
}
