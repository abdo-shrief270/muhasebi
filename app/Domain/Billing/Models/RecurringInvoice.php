<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Client\Models\Client;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('recurring_invoices')]
#[Fillable(['tenant_id', 'client_id', 'created_by', 'frequency', 'day_of_month', 'day_of_week', 'start_date', 'end_date', 'next_run_date', 'last_run_date', 'line_items', 'currency', 'notes', 'terms', 'due_days', 'is_active', 'auto_send', 'invoices_generated', 'max_occurrences'])]
class RecurringInvoice extends Model
{
    use BelongsToTenant, SoftDeletes, LogsActivity;

    protected function casts(): array
    {
        return [
            'line_items' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_run_date' => 'date',
            'last_run_date' => 'date',
            'is_active' => 'boolean',
            'auto_send' => 'boolean',
            'due_days' => 'integer',
            'invoices_generated' => 'integer',
            'max_occurrences' => 'integer',
            'day_of_month' => 'integer',
            'day_of_week' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['frequency', 'is_active', 'next_run_date', 'invoices_generated'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Recurring invoice {$eventName}");
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function createdByUser(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeActive($query) { return $query->where('is_active', true); }

    public function scopeDue($query)
    {
        return $query->active()
            ->where('next_run_date', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('max_occurrences')
                  ->orWhereColumn('invoices_generated', '<', 'max_occurrences');
            });
    }

    /**
     * Calculate the next run date based on frequency.
     */
    public function calculateNextRunDate(): string
    {
        $current = $this->next_run_date ?? now();

        return match ($this->frequency) {
            'weekly' => $current->copy()->addWeek()->toDateString(),
            'monthly' => $current->copy()->addMonth()->day(min($this->day_of_month ?? 1, 28))->toDateString(),
            'quarterly' => $current->copy()->addMonths(3)->toDateString(),
            'yearly' => $current->copy()->addYear()->toDateString(),
            default => $current->copy()->addMonth()->toDateString(),
        };
    }

    /**
     * Check if this schedule has reached its limit.
     */
    public function hasReachedLimit(): bool
    {
        if ($this->max_occurrences === null) return false;
        return $this->invoices_generated >= $this->max_occurrences;
    }

    /**
     * Check if this schedule has expired.
     */
    public function hasExpired(): bool
    {
        if ($this->end_date === null) return false;
        return $this->end_date->isPast();
    }
}
