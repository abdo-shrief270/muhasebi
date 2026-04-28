<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Tenant-owned bank account. Carries operational details (IBAN, SWIFT,
 * branch) and a pointer to the GL cash account it posts against. See
 * the migration for the rationale on why this is split from accounts.
 *
 * Used as the destination for expense reimbursements, bill payments,
 * and (eventually) AR receipts. The unique (tenant_id, iban) index
 * stops the most common operator mistake — entering the same account
 * twice — without preventing two accounts at the same bank from
 * coexisting.
 */
#[Table('bank_accounts')]
#[Fillable([
    'tenant_id',
    'account_name',
    'bank_name',
    'branch',
    'account_number',
    'iban',
    'swift_code',
    'currency',
    'gl_account_id',
    'opening_balance',
    'is_active',
    'notes',
])]
class BankAccount extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'currency' => 'EGP',
        'opening_balance' => 0,
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }

        return $q->where(function (Builder $sub) use ($term): void {
            $sub->where('account_name', 'ilike', "%{$term}%")
                ->orWhere('bank_name', 'ilike', "%{$term}%")
                ->orWhere('account_number', 'ilike', "%{$term}%")
                ->orWhere('iban', 'ilike', "%{$term}%");
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['account_name', 'bank_name', 'iban', 'currency', 'gl_account_id', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
