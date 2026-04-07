<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('eta_submissions')]
#[Fillable([
    'tenant_id',
    'submission_uuid',
    'status',
    'document_count',
    'accepted_count',
    'rejected_count',
    'response_data',
    'submitted_at',
    'submitted_by',
])]
class EtaSubmission extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'document_count' => 0,
        'accepted_count' => 0,
        'rejected_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_data' => 'array',
            'submitted_at' => 'datetime',
            'document_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EtaDocument::class);
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['submission_uuid', 'status', 'document_count'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
