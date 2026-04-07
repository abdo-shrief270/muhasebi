<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\EInvoice\Models\EtaSettings */
class EtaSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_enabled' => $this->is_enabled,
            'environment' => $this->environment,
            'client_id' => $this->client_id ? '****' . substr($this->client_id, -4) : null,
            'has_client_secret' => ! empty($this->client_secret),
            'branch_id' => $this->branch_id,
            'branch_address_country' => $this->branch_address_country,
            'branch_address_governate' => $this->branch_address_governate,
            'branch_address_region_city' => $this->branch_address_region_city,
            'branch_address_street' => $this->branch_address_street,
            'branch_address_building_number' => $this->branch_address_building_number,
            'activity_code' => $this->activity_code,
            'company_trade_name' => $this->company_trade_name,
            'token_valid' => $this->isTokenValid(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
