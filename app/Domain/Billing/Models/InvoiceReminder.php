<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Client\Models\Client;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('invoice_reminders')]
#[Fillable([
    'tenant_id',
    'invoice_id',
    'client_id',
    'days_overdue',
    'milestone',
    'channels_sent',
    'status',
    'error',
    'sent_at',
])]
class InvoiceReminder extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'channels_sent' => 'array',
            'days_overdue' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeForInvoice($query, int $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopeForMilestone($query, string $milestone)
    {
        return $query->where('milestone', $milestone);
    }
}
