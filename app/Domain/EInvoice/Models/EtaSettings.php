<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('eta_settings')]
#[Fillable([
    'tenant_id',
    'is_enabled',
    'environment',
    'client_id',
    'client_secret',
    'branch_id',
    'branch_address_country',
    'branch_address_governate',
    'branch_address_region_city',
    'branch_address_street',
    'branch_address_building_number',
    'activity_code',
    'company_trade_name',
    'access_token',
    'token_expires_at',
])]
class EtaSettings extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_enabled' => false,
        'environment' => 'preprod',
        'branch_id' => '0',
        'branch_address_country' => 'EG',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function isTokenValid(): bool
    {
        return $this->access_token
            && $this->token_expires_at
            && $this->token_expires_at->isFuture();
    }

    public function getBaseUrl(): string
    {
        if ($this->environment === 'production') {
            return 'https://api.invoicing.eta.gov.eg/api/v1.0';
        }

        return 'https://api.preprod.invoicing.eta.gov.eg/api/v1.0';
    }

    public function getTokenUrl(): string
    {
        if ($this->environment === 'production') {
            return 'https://id.eta.gov.eg/connect/token';
        }

        return 'https://id.preprod.eta.gov.eg/connect/token';
    }
}
