<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tax\Enums\WhtCertificateStatus;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('wht_certificates')]
#[Fillable([
    'tenant_id',
    'vendor_id',
    'certificate_number',
    'period_from',
    'period_to',
    'total_payments',
    'total_wht',
    'status',
    'issued_at',
    'submitted_at',
    'data',
])]
class WhtCertificate extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'total_payments' => 'decimal:2',
            'total_wht' => 'decimal:2',
            'status' => WhtCertificateStatus::class,
            'issued_at' => 'datetime',
            'submitted_at' => 'datetime',
            'data' => 'array',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'total_payments' => 0,
        'total_wht' => 0,
    ];

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOfStatus(Builder $query, WhtCertificateStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForPeriod(Builder $query, string $from, string $to): Builder
    {
        return $query->where('period_from', '>=', $from)
            ->where('period_to', '<=', $to);
    }
}
