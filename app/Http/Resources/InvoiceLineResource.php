<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceLine */
class InvoiceLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount_percent' => $this->discount_percent,
            'vat_rate' => $this->vat_rate,
            'line_total' => $this->line_total,
            'vat_amount' => $this->vat_amount,
            'total' => $this->total,
            'sort_order' => $this->sort_order,
            'account_id' => $this->account_id,
        ];
    }
}
