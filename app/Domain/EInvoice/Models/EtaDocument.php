<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\EInvoice\Enums\EtaDocumentStatus;
use App\Domain\EInvoice\Enums\EtaDocumentType;
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

#[Table('eta_documents')]
#[Fillable([
    'tenant_id',
    'invoice_id',
    'eta_submission_id',
    'document_type',
    'internal_id',
    'eta_uuid',
    'eta_long_id',
    'status',
    'signed_data',
    'document_data',
    'eta_response',
    'errors',
    'qr_code_data',
    'submitted_at',
    'cancelled_at',
    'cancelled_by',
])]
class EtaDocument extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'prepared',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => EtaDocumentType::class,
            'status' => EtaDocumentStatus::class,
            'document_data' => 'array',
            'eta_response' => 'array',
            'errors' => 'array',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EtaSubmission::class, 'eta_submission_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOfStatus(Builder $query, EtaDocumentStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForInvoice(Builder $query, int $invoiceId): Builder
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', EtaDocumentStatus::Submitted);
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['eta_uuid', 'status', 'invoice_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
