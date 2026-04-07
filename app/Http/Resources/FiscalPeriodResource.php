<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Accounting\Models\FiscalPeriod */
class FiscalPeriodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fiscal_year_id' => $this->fiscal_year_id,
            'name' => $this->name,
            'period_number' => $this->period_number,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'is_closed' => $this->is_closed,
            'closed_at' => $this->closed_at?->toISOString(),
        ];
    }
}
