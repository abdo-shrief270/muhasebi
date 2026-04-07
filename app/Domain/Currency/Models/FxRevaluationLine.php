<?php

declare(strict_types=1);

namespace App\Domain\Currency\Models;

use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('fx_revaluation_lines')]
#[Fillable([
    'revaluation_id',
    'account_id',
    'currency',
    'original_balance',
    'original_rate',
    'new_rate',
    'revalued_balance',
    'gain_loss',
])]
class FxRevaluationLine extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'original_balance' => 'decimal:2',
            'original_rate' => 'decimal:6',
            'new_rate' => 'decimal:6',
            'revalued_balance' => 'decimal:2',
            'gain_loss' => 'decimal:2',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function revaluation(): BelongsTo
    {
        return $this->belongsTo(FxRevaluation::class, 'revaluation_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
