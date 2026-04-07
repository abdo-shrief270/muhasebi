<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('invoice_settings')]
#[Fillable([
    'tenant_id',
    'invoice_prefix',
    'credit_note_prefix',
    'debit_note_prefix',
    'next_invoice_number',
    'next_credit_note_number',
    'next_debit_note_number',
    'default_due_days',
    'default_vat_rate',
    'default_payment_terms',
    'default_notes',
    'ar_account_id',
    'revenue_account_id',
    'vat_account_id',
])]
class InvoiceSettings extends Model
{
    use BelongsToTenant;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_due_days' => 'integer',
            'default_vat_rate' => 'decimal:2',
            'next_invoice_number' => 'integer',
            'next_credit_note_number' => 'integer',
            'next_debit_note_number' => 'integer',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'invoice_prefix' => 'INV',
        'credit_note_prefix' => 'CN',
        'debit_note_prefix' => 'DN',
        'next_invoice_number' => 1,
        'next_credit_note_number' => 1,
        'next_debit_note_number' => 1,
        'default_due_days' => 30,
        'default_vat_rate' => 14.00,
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function arAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ar_account_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function vatAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'vat_account_id');
    }
}
