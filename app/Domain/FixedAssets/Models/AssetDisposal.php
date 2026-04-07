<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\FixedAssets\Enums\DisposalType;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('asset_disposals')]
#[Fillable([
    'tenant_id',
    'fixed_asset_id',
    'journal_entry_id',
    'disposal_type',
    'disposal_date',
    'disposal_amount',
    'book_value_at_disposal',
    'accumulated_depreciation_at_disposal',
    'gain_or_loss',
    'buyer_name',
    'created_by',
    'notes',
])]
class AssetDisposal extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'disposal_type' => DisposalType::class,
            'disposal_date' => 'date',
            'disposal_amount' => 'decimal:2',
            'book_value_at_disposal' => 'decimal:2',
            'accumulated_depreciation_at_disposal' => 'decimal:2',
            'gain_or_loss' => 'decimal:2',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['disposal_type', 'disposal_date', 'disposal_amount', 'gain_or_loss'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
