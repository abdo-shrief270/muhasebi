<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\EInvoice\Models\EtaItemCode */
class EtaItemCodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code_type' => $this->code_type,
            'item_code' => $this->item_code,
            'description' => $this->description,
            'description_ar' => $this->description_ar,
            'unit_type' => $this->unit_type,
            'default_tax_type' => $this->default_tax_type,
            'default_tax_subtype' => $this->default_tax_subtype,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
