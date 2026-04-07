<?php

declare(strict_types=1);

namespace App\Domain\ECommerce\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('ecommerce_orders')]
#[Fillable([
    'tenant_id',
    'channel_id',
    'external_order_id',
    'order_number',
    'status',
    'customer_name',
    'customer_email',
    'total',
    'currency',
    'tax_amount',
    'shipping_amount',
    'items',
    'synced_invoice_id',
    'synced_at',
    'raw_data',
])]
class ECommerceOrder extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'items' => 'array',
            'raw_data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ECommerceChannel::class, 'channel_id');
    }

    public function syncedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'synced_invoice_id');
    }
}
