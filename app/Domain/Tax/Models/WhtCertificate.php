<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\Tax\Enums\WhtCertificateStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\WhtCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('wht_certificates')]
#[Fillable([
    'tenant_id',
    'certificate_number',
    'vendor_name',
    'vendor_tax_id',
    'period_from',
    'period_to',
    'total_wht_amount',
    'status',
    'issued_at',
    'submitted_at',
    'notes',
])]
class WhtCertificate extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'total_wht_amount' => 'decimal:2',
            'status' => WhtCertificateStatus::class,
            'issued_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'total_wht_amount' => 0,
    ];

    // ──────────────────────────────────────
    // Factory
    // ──────────────────────────────────────

    protected static function newFactory(): WhtCertificateFactory
    {
        return WhtCertificateFactory::new();
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bills(): BelongsToMany
    {
        return $this->belongsToMany(Bill::class, 'wht_certificate_bills')
            ->withPivot('wht_amount')
            ->withTimestamps();
    }
}
