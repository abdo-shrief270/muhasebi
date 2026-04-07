<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
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
            'client_id' => $this->client_id,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'vat_amount' => $this->vat_amount,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance_due' => $this->balanceDue(),
            'currency' => $this->currency,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'sent_at' => $this->sent_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'journal_entry_id' => $this->journal_entry_id,
            'original_invoice_id' => $this->original_invoice_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Conditional relations
            'client' => new ClientResource($this->whenLoaded('client')),
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'credit_notes' => InvoiceResource::collection($this->whenLoaded('creditNotes')),
            'eta_document' => new EtaDocumentResource($this->whenLoaded('etaDocument')),
            'created_by_user' => $this->whenLoaded('createdByUser', fn () => [
                'id' => $this->createdByUser->id,
                'name' => $this->createdByUser->name,
            ]),
        ];
    }
}
