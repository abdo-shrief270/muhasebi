<?php

declare(strict_types=1);

namespace App\Domain\Collection\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;
use App\Domain\Collection\Enums\CollectionActionType;
use App\Domain\Collection\Enums\CollectionOutcome;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('collection_actions')]
#[Fillable([
    'tenant_id',
    'invoice_id',
    'client_id',
    'action_type',
    'outcome',
    'notes',
    'action_date',
    'commitment_date',
    'commitment_amount',
    'created_by',
])]
class CollectionAction extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action_date' => 'date',
            'commitment_date' => 'date',
            'commitment_amount' => 'decimal:2',
            'action_type' => CollectionActionType::class,
            'outcome' => CollectionOutcome::class,
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

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForInvoice(Builder $query, int $invoiceId): Builder
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeOfType(Builder $query, CollectionActionType $type): Builder
    {
        return $query->where('action_type', $type);
    }

    public function scopeOfOutcome(Builder $query, CollectionOutcome $outcome): Builder
    {
        return $query->where('outcome', $outcome);
    }

    public function scopeDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('action_date', [$from, $to]);
    }

    public function scopeUpcomingCommitments(Builder $query): Builder
    {
        return $query->whereNotNull('commitment_date')
            ->where('commitment_date', '>=', today());
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['action_type', 'outcome', 'commitment_date', 'commitment_amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
