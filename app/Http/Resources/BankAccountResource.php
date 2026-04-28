<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Banking\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankAccount
 */
class BankAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,

            'account_name' => $this->account_name,
            'bank_name' => $this->bank_name,
            'branch' => $this->branch,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'swift_code' => $this->swift_code,
            'currency' => $this->currency,

            'gl_account_id' => $this->gl_account_id,
            'gl_account' => $this->whenLoaded('glAccount', fn () => $this->glAccount ? [
                'id' => $this->glAccount->id,
                'code' => $this->glAccount->code,
                'name_ar' => $this->glAccount->name_ar,
                'name_en' => $this->glAccount->name_en,
            ] : null),

            'opening_balance' => $this->opening_balance,
            'is_active' => $this->is_active,
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
