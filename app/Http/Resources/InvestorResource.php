<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Investor\Models\Investor */
class InvestorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'join_date' => $this->join_date?->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'tenant_shares_count' => $this->whenCounted('tenantShares'),
            'created_at' => $this->created_at?->toISOString(),

            'tenant_shares' => InvestorTenantShareResource::collection($this->whenLoaded('tenantShares')),
        ];
    }
}
