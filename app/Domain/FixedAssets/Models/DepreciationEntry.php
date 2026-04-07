<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('depreciation_entries')]
#[Fillable([
    'tenant_id',
    'fixed_asset_id',
    'journal_entry_id',
    'period_end',
    'amount',
    'accumulated_after',
    'book_value_after',
])]
class DepreciationEntry extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'amount' => 'decimal:2',
            'accumulated_after' => 'decimal:2',
            'book_value_after' => 'decimal:2',
        ];
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
