<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('budget_lines')]
#[Fillable([
    'budget_id',
    'account_id',
    'annual_amount',
    'm1', 'm2', 'm3', 'm4', 'm5', 'm6',
    'm7', 'm8', 'm9', 'm10', 'm11', 'm12',
])]
class BudgetLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'annual_amount' => 'decimal:2',
            'm1' => 'decimal:2', 'm2' => 'decimal:2', 'm3' => 'decimal:2',
            'm4' => 'decimal:2', 'm5' => 'decimal:2', 'm6' => 'decimal:2',
            'm7' => 'decimal:2', 'm8' => 'decimal:2', 'm9' => 'decimal:2',
            'm10' => 'decimal:2', 'm11' => 'decimal:2', 'm12' => 'decimal:2',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get budget amount for a specific month (1-12).
     */
    public function amountForMonth(int $month): string
    {
        $field = "m{$month}";

        return (string) ($this->{$field} ?? '0.00');
    }

    /**
     * Get budget amount for a range of months.
     */
    public function amountForRange(int $fromMonth, int $toMonth): string
    {
        $total = '0.00';

        for ($m = $fromMonth; $m <= $toMonth; $m++) {
            $total = bcadd($total, $this->amountForMonth($m), 2);
        }

        return $total;
    }

    /**
     * Distribute annual amount evenly across 12 months.
     */
    public function distributeEvenly(): void
    {
        $monthly = bcdiv((string) $this->annual_amount, '12', 2);
        $remainder = bcsub((string) $this->annual_amount, bcmul($monthly, '12', 2), 2);

        for ($m = 1; $m <= 12; $m++) {
            $this->{"m{$m}"} = $monthly;
        }

        // Put remainder in last month
        $this->m12 = bcadd($monthly, $remainder, 2);
    }
}
