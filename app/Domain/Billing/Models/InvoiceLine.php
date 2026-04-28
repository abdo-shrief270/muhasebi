<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Client\Models\ClientProduct;
use App\Domain\Shared\Traits\BelongsToTenant;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('invoice_lines')]
#[Fillable([
    'invoice_id',
    'client_product_id',
    'description',
    'quantity',
    'unit_price',
    'discount_percent',
    'vat_rate',
    'line_total',
    'vat_amount',
    'total',
    'sort_order',
    'account_id',
])]
class InvoiceLine extends Model
{
    use BelongsToTenant, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'quantity' => 1,
        'discount_percent' => 0,
        'vat_rate' => 14.00,
        'sort_order' => 0,
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): InvoiceLineFactory
    {
        return InvoiceLineFactory::new();
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function clientProduct(): BelongsTo
    {
        return $this->belongsTo(ClientProduct::class);
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    /**
     * Calculate and set line_total, vat_amount, and total
     * from quantity, unit_price, discount_percent, and vat_rate.
     */
    public function calculateTotals(): void
    {
        $gross = bcmul((string) $this->quantity, (string) $this->unit_price, 2);
        $discountAmount = bcmul($gross, bcdiv((string) $this->discount_percent, '100', 6), 2);
        $lineTotal = bcsub($gross, $discountAmount, 2);
        $vatAmount = bcmul($lineTotal, bcdiv((string) $this->vat_rate, '100', 6), 2);
        $total = bcadd($lineTotal, $vatAmount, 2);

        $this->line_total = $lineTotal;
        $this->vat_amount = $vatAmount;
        $this->total = $total;
    }
}
