<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\EInvoice\Models\EtaSubmission */
class EtaSubmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_uuid' => $this->submission_uuid,
            'status' => $this->status,
            'document_count' => $this->document_count,
            'accepted_count' => $this->accepted_count,
            'rejected_count' => $this->rejected_count,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),

            'submitted_by_user' => $this->whenLoaded('submittedByUser', fn () => [
                'id' => $this->submittedByUser->id,
                'name' => $this->submittedByUser->name,
            ]),
        ];
    }
}
