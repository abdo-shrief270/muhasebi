<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Enums\BankCode;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Table('bank_connections')]
#[Fillable([
    'tenant_id',
    'bank_code',
    'account_number',
    'iban',
    'account_name',
    'currency',
    'connection_type',
    'api_credentials',
    'last_sync_at',
    'sync_status',
    'balance',
    'balance_date',
    'is_active',
    'linked_gl_account_id',
    'notes',
    'created_by',
])]
class BankConnection extends Model
{
    use BelongsToTenant;
    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bank_code' => BankCode::class,
            'api_credentials' => 'encrypted:array',
            'last_sync_at' => 'datetime',
            'balance' => 'decimal:2',
            'balance_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'EGP',
        'connection_type' => 'manual',
        'sync_status' => 'disconnected',
        'is_active' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bank_code', 'account_number', 'sync_status', 'balance', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "Bank connection {$eventName}");
    }

    // ── Relationships ──

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'linked_gl_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForBank(Builder $query, BankCode $bankCode): Builder
    {
        return $query->where('bank_code', $bankCode);
    }

    // ── Helpers ──

    public function isConnected(): bool
    {
        return $this->sync_status === 'connected';
    }

    public function isApiEnabled(): bool
    {
        return $this->connection_type === 'api' && $this->bank_code->supportsApi();
    }
}
