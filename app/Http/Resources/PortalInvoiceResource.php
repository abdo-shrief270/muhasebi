<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class PortalInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'type' => $this->type?->value,
            'date' => $this->date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status?->value,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'vat_amount' => $this->vat_amount,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance_due' => $this->balanceDue(),
            'currency' => $this->currency,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'created_at' => $this->created_at?->toISOString(),

            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
