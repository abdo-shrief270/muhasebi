<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'email', 'phone', 'company', 'subject', 'message', 'is_read', 'admin_notes', 'status', 'assigned_to'])]
class ContactSubmission extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'assigned_to', 'is_read', 'admin_notes'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Contact submission {$eventName}");
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
