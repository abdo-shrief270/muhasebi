<?php

declare(strict_types=1);

namespace App\Domain\Currency\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('fx_revaluations')]
#[Fillable([
    'tenant_id',
    'revaluation_date',
    'functional_currency',
    'status',
    'total_gain',
    'total_loss',
    'net_gain_loss',
    'journal_entry_id',
    'notes',
    'created_by',
])]
class FxRevaluation extends Model
{
    use BelongsToTenant;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revaluation_date' => 'date',
            'total_gain' => 'decimal:2',
            'total_loss' => 'decimal:2',
            'net_gain_loss' => 'decimal:2',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'functional_currency' => 'EGP',
        'status' => 'draft',
        'total_gain' => 0,
        'total_loss' => 0,
        'net_gain_loss' => 0,
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FxRevaluationLine::class, 'revaluation_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    // ──────────────────────────────────────
    // Activity Log
    // ──────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'revaluation_date', 'total_gain', 'total_loss', 'net_gain_loss'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
