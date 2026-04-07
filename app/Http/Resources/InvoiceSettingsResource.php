<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Billing\Models\InvoiceSettings */
class InvoiceSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_prefix' => $this->invoice_prefix,
            'credit_note_prefix' => $this->credit_note_prefix,
            'debit_note_prefix' => $this->debit_note_prefix,
            'next_invoice_number' => $this->next_invoice_number,
            'next_credit_note_number' => $this->next_credit_note_number,
            'next_debit_note_number' => $this->next_debit_note_number,
            'default_due_days' => $this->default_due_days,
            'default_vat_rate' => $this->default_vat_rate,
            'default_payment_terms' => $this->default_payment_terms,
            'default_notes' => $this->default_notes,
            'ar_account_id' => $this->ar_account_id,
            'revenue_account_id' => $this->revenue_account_id,
            'vat_account_id' => $this->vat_account_id,

            // Conditional relations
            'ar_account' => $this->whenLoaded('arAccount', fn () => [
                'id' => $this->arAccount->id,
                'code' => $this->arAccount->code,
                'name_ar' => $this->arAccount->name_ar,
                'name_en' => $this->arAccount->name_en,
            ]),
            'revenue_account' => $this->whenLoaded('revenueAccount', fn () => [
                'id' => $this->revenueAccount->id,
                'code' => $this->revenueAccount->code,
                'name_ar' => $this->revenueAccount->name_ar,
                'name_en' => $this->revenueAccount->name_en,
            ]),
            'vat_account' => $this->whenLoaded('vatAccount', fn () => [
                'id' => $this->vatAccount->id,
                'code' => $this->vatAccount->code,
                'name_ar' => $this->vatAccount->name_ar,
                'name_en' => $this->vatAccount->name_en,
            ]),
        ];
    }
}
