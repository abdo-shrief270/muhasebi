<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Investor\Models\ProfitDistribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProfitDistribution */
class ProfitDistributionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'investor_id' => $this->investor_id,
            'tenant_id' => $this->tenant_id,
            'month' => $this->month,
            'year' => $this->year,
            'tenant_revenue' => $this->tenant_revenue,
            'tenant_expenses' => $this->tenant_expenses,
            'net_profit' => $this->net_profit,
            'ownership_percentage' => $this->ownership_percentage,
            'investor_share' => $this->investor_share,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_label_ar' => $this->status?->labelAr(),
            'paid_at' => $this->paid_at?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),

            'investor' => new InvestorResource($this->whenLoaded('investor')),
            'tenant' => new AdminTenantResource($this->whenLoaded('tenant')),
        ];
    }
}
