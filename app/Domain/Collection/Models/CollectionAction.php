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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('collection_actions')]
#[Fillable([
    'tenant_id',
    'invoice_id',
    'client_id',
    'action_type',
    'action_date',
    'notes',
    'outcome',
    'commitment_date',
    'commitment_amount',
    'performed_by',
])]
class CollectionAction extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action_type' => CollectionActionType::class,
            'outcome' => CollectionOutcome::class,
            'action_date' => 'date',
            'commitment_date' => 'date',
            'commitment_amount' => 'decimal:2',
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

    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['action_type', 'outcome', 'action_date', 'commitment_date', 'commitment_amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
