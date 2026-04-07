<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

use App\Domain\EInvoice\Enums\AmendmentStatus;
use App\Domain\EInvoice\Enums\AmendmentType;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('eta_amendments')]
#[Fillable([
    'tenant_id',
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
])]
class EtaAmendment extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amendment_type' => AmendmentType::class,
            'status' => AmendmentStatus::class,
            'submitted_at' => 'datetime',
            'response_at' => 'datetime',
            'response_data' => 'array',
            'deadline' => 'date',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

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
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amendment_type', 'status', 'eta_reference', 'original_document_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
