<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Database\Factories\ScheduledReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('scheduled_reports')]
#[Fillable([
    'tenant_id',
    'report_type',
    'report_config',
    'schedule_type',
    'schedule_day',
    'schedule_time',
    'format',
    'recipients',
    'subject_template',
    'is_active',
    'last_sent_at',
    'next_send_at',
    'created_by',
])]
class ScheduledReport extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity, SoftDeletes;

    protected static function newFactory(): ScheduledReportFactory
    {
        return ScheduledReportFactory::new();
    }

    protected function casts(): array
    {
        return [
            'report_config' => 'array',
            'recipients' => 'array',
            'is_active' => 'boolean',
            'schedule_day' => 'integer',
            'last_sent_at' => 'datetime',
            'next_send_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['report_type', 'schedule_type', 'is_active', 'recipients', 'next_send_at'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Scheduled report {$eventName}");
    }

    // ── Relationships ────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
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
        return $query->active()->where('next_send_at', '<=', now());
    }
}
