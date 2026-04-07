<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\EInvoice\Enums\EtaAmendmentStatus;
use App\Domain\EInvoice\Enums\EtaAmendmentType;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('eta_amendments')]
#[Fillable([
    'tenant_id',
    'eta_document_id',
    'corrected_invoice_id',
    'type',
    'status',
    'reason_ar',
    'reason_en',
    'deadline_at',
    'submitted_at',
    'submitted_by',
    'response_at',
    'response_data',
])]
class EtaAmendment extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => EtaAmendmentType::class,
            'status' => EtaAmendmentStatus::class,
            'response_data' => 'array',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'response_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function etaDocument(): BelongsTo
    {
        return $this->belongsTo(EtaDocument::class);
    }

    public function correctedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'corrected_invoice_id');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOfStatus(Builder $query, EtaAmendmentStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeOfType(Builder $query, EtaAmendmentType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeForDocument(Builder $query, int $documentId): Builder
    {
        return $query->where('eta_document_id', $documentId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', EtaAmendmentStatus::Pending);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', EtaAmendmentStatus::Pending)
            ->where('deadline_at', '<', now());
    }

    public function scopeApproachingDeadline(Builder $query, int $withinHours = 24): Builder
    {
        return $query->where('status', EtaAmendmentStatus::Pending)
            ->where('deadline_at', '>', now())
            ->where('deadline_at', '<=', now()->addHours($withinHours));
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function canSubmit(): bool
    {
        return $this->status->canSubmit();
    }

    public function isOverdue(): bool
    {
        return $this->status === EtaAmendmentStatus::Pending
            && $this->deadline_at
            && $this->deadline_at->isPast();
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'status', 'eta_document_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
