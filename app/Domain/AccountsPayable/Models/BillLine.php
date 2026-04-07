<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('bill_lines')]
#[Fillable([
    'bill_id',
    'description',
    'quantity',
    'unit_price',
    'discount_percent',
    'vat_rate',
    'wht_rate',
    'line_total',
    'vat_amount',
    'wht_amount',
    'total',
    'sort_order',
    'account_id',
])]
class BillLine extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'wht_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'wht_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'quantity' => 1,
        'discount_percent' => 0,
        'vat_rate' => 14.00,
        'wht_rate' => 0,
        'sort_order' => 0,
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    // ──────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────

    /**
     * Calculate and set line_total, vat_amount, wht_amount, and total
     * from quantity, unit_price, discount_percent, vat_rate, and wht_rate.
     */
    public function calculateTotals(): void
    {
        $gross = bcmul((string) $this->quantity, (string) $this->unit_price, 2);
        $discountAmount = bcmul($gross, bcdiv((string) $this->discount_percent, '100', 6), 2);
        $lineTotal = bcsub($gross, $discountAmount, 2);
        $vatAmount = bcmul($lineTotal, bcdiv((string) $this->vat_rate, '100', 6), 2);
        $whtAmount = bcmul($lineTotal, bcdiv((string) $this->wht_rate, '100', 6), 2);
        $total = bcsub(bcadd($lineTotal, $vatAmount, 2), $whtAmount, 2);

        $this->line_total = $lineTotal;
        $this->vat_amount = $vatAmount;
        $this->wht_amount = $whtAmount;
        $this->total = $total;
    }
}
