<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Investor\Models\InvestorTenantShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvestorTenantShare */
class InvestorTenantShareResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'investor_id' => $this->investor_id,
            'tenant_id' => $this->tenant_id,
            'ownership_percentage' => $this->ownership_percentage,
            'created_at' => $this->created_at?->toISOString(),

            'tenant' => new AdminTenantResource($this->whenLoaded('tenant')),
        ];
    }
}
