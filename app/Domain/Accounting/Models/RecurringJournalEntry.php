<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\RecurringFrequency;
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

#[Table('recurring_journal_entries')]
#[Fillable([
    'tenant_id',
    'template_name_ar',
    'template_name_en',
    'description',
    'frequency',
    'lines',
    'next_run_date',
    'last_run_date',
    'end_date',
    'is_active',
    'run_count',
    'created_by',
])]
class RecurringJournalEntry extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'frequency' => RecurringFrequency::class,
            'lines' => 'array',
            'next_run_date' => 'date',
            'last_run_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'run_count' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['template_name_ar', 'frequency', 'is_active', 'next_run_date', 'run_count'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Recurring journal entry {$eventName}");
    }

    // ── Relationships ────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ───────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->active()
            ->where('next_run_date', '<=', now()->toDateString())
            ->where(function (Builder $q): void {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            });
    }
}
