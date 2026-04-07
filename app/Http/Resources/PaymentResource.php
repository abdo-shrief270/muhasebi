<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'date' => $this->date?->toDateString(),
            'method' => $this->method?->value,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'journal_entry_id' => $this->journal_entry_id,
            'created_at' => $this->created_at?->toISOString(),

            // Conditional relations
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
            'created_by_user' => $this->whenLoaded('createdByUser', fn () => [
                'id' => $this->createdByUser->id,
                'name' => $this->createdByUser->name,
            ]),
        ];
    }
}
