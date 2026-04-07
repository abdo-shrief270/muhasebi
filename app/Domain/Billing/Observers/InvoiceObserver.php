<?php

declare(strict_types=1);

namespace App\Domain\Billing\Observers;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Webhook\Services\WebhookService;
use Illuminate\Support\Facades\Log;

class InvoiceObserver
{
    public function created(Invoice $invoice): void
    {
        $this->dispatchWebhook($invoice, 'invoice.created');
    }

    public function updated(Invoice $invoice): void
    {
        if ($invoice->wasChanged('status')) {
            $this->dispatchWebhook($invoice, 'invoice.updated');
        }
    }

    private function dispatchWebhook(Invoice $invoice, string $event): void
    {
        try {
            WebhookService::dispatch($invoice->tenant_id, $event, $invoice->toArray());
        } catch (\Throwable $e) {
            Log::warning("Webhook dispatch failed for {$event}", [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
