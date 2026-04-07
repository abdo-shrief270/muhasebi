<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

<<<<<<< HEAD
use App\Domain\EInvoice\Enums\AmendmentStatus;
use App\Domain\EInvoice\Enums\AmendmentType;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
=======
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
>>>>>>> feat/amend-2
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('eta_amendments')]
#[Fillable([
    'tenant_id',
<<<<<<< HEAD
    'original_document_id',
    'amendment_type',
    'reason_ar',
    'reason_en',
    'status',
    'eta_reference',
    'submitted_at',
    'response_at',
    'response_data',
    'deadline',
    'amended_document_id',
    'created_by',
=======
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
>>>>>>> feat/amend-2
])]
class EtaAmendment extends Model
{
    use BelongsToTenant;
    use LogsActivity;
<<<<<<< HEAD
    use SoftDeletes;
=======
>>>>>>> feat/amend-2

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
<<<<<<< HEAD
            'amendment_type' => AmendmentType::class,
            'status' => AmendmentStatus::class,
            'submitted_at' => 'datetime',
            'response_at' => 'datetime',
            'response_data' => 'array',
            'deadline' => 'date',
=======
            'type' => EtaAmendmentType::class,
            'status' => EtaAmendmentStatus::class,
            'response_data' => 'array',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'response_at' => 'datetime',
>>>>>>> feat/amend-2
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

<<<<<<< HEAD
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(EtaDocument::class, 'original_document_id');
    }

    public function amendedDocument(): BelongsTo
    {
        return $this->belongsTo(EtaDocument::class, 'amended_document_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Business Logic
    // ──────────────────────────────────────

    /**
     * Whether the amendment deadline has passed and status is still pending.
     */
    public function isOverdue(): bool
    {
        return $this->deadline !== null
            && $this->deadline->isPast()
            && $this->status === AmendmentStatus::Pending;
    }

    /**
     * Number of days remaining until the deadline (negative if overdue).
     */
    public function daysUntilDeadline(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->deadline, false);
=======
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
>>>>>>> feat/amend-2
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
<<<<<<< HEAD
            ->logOnly(['amendment_type', 'status', 'eta_reference', 'original_document_id'])
=======
            ->logOnly(['type', 'status', 'eta_document_id'])
>>>>>>> feat/amend-2
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
