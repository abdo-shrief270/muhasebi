<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('investor_tenant_shares')]
#[Fillable([
    'investor_id',
    'tenant_id',
    'ownership_percentage',
])]
class InvestorTenantShare extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ownership_percentage' => 'decimal:2',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
