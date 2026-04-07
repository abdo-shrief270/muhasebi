<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Accounting\Models\JournalEntry */
class JournalEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_number' => $this->entry_number,
            'date' => $this->date,
            'description' => $this->description,
            'reference' => $this->reference,
            'status' => $this->status,
            'total_debit' => $this->total_debit,
            'total_credit' => $this->total_credit,
            'fiscal_period_id' => $this->fiscal_period_id,
            'posted_at' => $this->posted_at?->toISOString(),
            'reversed_at' => $this->reversed_at?->toISOString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'lines' => JournalEntryLineResource::collection($this->whenLoaded('lines')),
            'fiscal_period' => new FiscalPeriodResource($this->whenLoaded('fiscalPeriod')),
            'created_by_user' => $this->whenLoaded('createdByUser'),
        ];
    }
}
