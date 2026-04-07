<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Accounting\Models\JournalEntryLine */
class JournalEntryLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
            'cost_center' => $this->cost_center,
            'account' => $this->when($this->relationLoaded('account'), fn () => [
                'code' => $this->account->code,
                'name_ar' => $this->account->name_ar,
                'name_en' => $this->account->name_en,
            ]),
        ];
    }
}
