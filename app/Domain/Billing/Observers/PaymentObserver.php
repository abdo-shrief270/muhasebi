<?php

declare(strict_types=1);

namespace App\Domain\Billing\Observers;

use App\Domain\Billing\Models\Payment;
use App\Domain\Webhook\Services\WebhookService;
use Illuminate\Support\Facades\Log;

class PaymentObserver
{
    public function created(Payment $payment): void
    {
        try {
            WebhookService::dispatch($payment->tenant_id, 'payment.received', [
                'payment' => $payment->toArray(),
                'invoice_id' => $payment->invoice_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webhook dispatch failed for payment.received', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
