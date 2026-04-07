<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\EInvoice\Models\EtaDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EtaDocument */
class EtaDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'document_type' => $this->document_type?->value,
            'internal_id' => $this->internal_id,
            'eta_uuid' => $this->eta_uuid,
            'eta_long_id' => $this->eta_long_id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_label_ar' => $this->status?->labelAr(),
            'status_color' => $this->status?->color(),
            'errors' => $this->errors,
            'qr_code_data' => $this->qr_code_data,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Conditional relations
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
            'submission' => new EtaSubmissionResource($this->whenLoaded('submission')),
        ];
    }
}
