<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Integration\Models\IntegrationSetting;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Subscription\Services\FawryService;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ClientPaymentService
{
    private const BASE_URL = 'https://accept.paymob.com/api';

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Get available payment gateways for the portal.
     *
     * @return array<int, array{key: string, name: string, name_ar: string}>
     */
    public function availableGateways(): array
    {
        $gateways = [];

        if ($this->isConfigured()) {
            $gateways[] = ['key' => 'paymob', 'name' => 'Credit/Debit Card', 'name_ar' => 'بطاقة ائتمان / خصم'];
        }

        if (FawryService::isConfigured()) {
            $gateways[] = ['key' => 'fawry', 'name' => 'Fawry', 'name_ar' => 'فوري'];
        }

        return $gateways;
    }

    /**
     * Initiate a payment for an invoice via the selected gateway.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function initiatePayment(Invoice $invoice, User $clientUser, string $gateway = 'paymob'): array
    {
        $this->validateInvoicePayable($invoice, $clientUser);

        return match ($gateway) {
            'fawry' => $this->initiateFawryPayment($invoice),
            default => $this->initiatePaymobPayment($invoice),
        };
    }

    /**
     * Initiate a Paymob payment for an invoice.
     *
     * @return array<string, mixed>
     */
    private function initiatePaymobPayment(Invoice $invoice): array
    {
        if (! $this->isConfigured()) {
            return ['payment_url' => null, 'order_id' => null, 'gateway' => 'paymob', 'configured' => false];
        }

        $balanceDue = $invoice->balanceDue();
        $authToken = $this->authenticate();
        $amountCents = (int) round($balanceDue * 100);

        $orderId = $this->createOrder($authToken, $amountCents, $invoice);
        $paymentKey = $this->generatePaymentKey($authToken, $orderId, $amountCents, $invoice);

        $iframeId = config('services.paymob.iframe_id');
        $paymentUrl = "https://accept.paymob.com/api/acceptance/iframes/{$iframeId}?payment_token={$paymentKey}";

        return [
            'payment_url' => $paymentUrl,
            'order_id' => (string) $orderId,
            'invoice_id' => $invoice->id,
            'amount' => $balanceDue,
            'gateway' => 'paymob',
            'configured' => true,
        ];
    }

    /**
     * Initiate a Fawry payment for an invoice.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function initiateFawryPayment(Invoice $invoice): array
    {
        if (! FawryService::isConfigured()) {
            return ['payment_url' => null, 'reference_number' => null, 'gateway' => 'fawry', 'configured' => false];
        }

        $balanceDue = $invoice->balanceDue();
        $client = $invoice->client;
        $merchantRef = "INV-FAWRY-{$invoice->id}-" . time();

        $result = FawryService::createCharge([
            'reference' => $merchantRef,
            'amount' => $balanceDue,
            'customer_id' => (string) $invoice->client_id,
            'customer_name' => $client?->name ?? 'Client',
            'customer_email' => $client?->email ?? '',
            'customer_phone' => $client?->phone ?? '',
            'item_id' => "INV-{$invoice->id}",
            'description' => "Invoice {$invoice->invoice_number} payment",
            'payment_method' => 'PAYATFAWRY',
        ]);

        if (! ($result['success'] ?? false)) {
            throw ValidationException::withMessages([
                'gateway' => [
                    'Failed to create Fawry payment. ' . ($result['error'] ?? ''),
                    'فشل في إنشاء طلب الدفع عبر فوري.',
                ],
            ]);
        }

        return [
            'reference_number' => $result['fawry_ref'],
            'merchant_ref' => $merchantRef,
            'invoice_id' => $invoice->id,
            'amount' => $balanceDue,
            'expiry_date' => $result['expiry_date'],
            'gateway' => 'fawry',
            'configured' => true,
        ];
    }

    /**
     * Validate that an invoice can be paid by the given client user.
     *
     * @throws ValidationException
     */
    private function validateInvoicePayable(Invoice $invoice, User $clientUser): void
    {
        $payableStatuses = [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue];

        if (! in_array($invoice->status, $payableStatuses, true)) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'This invoice cannot be paid in its current status.',
                    'لا يمكن دفع هذه الفاتورة بحالتها الحالية.',
                ],
            ]);
        }

        abort_if($invoice->client_id !== $clientUser->client_id, 403);

        if ($invoice->balanceDue() <= 0) {
            throw ValidationException::withMessages([
                'invoice' => [
                    'This invoice has no balance due.',
                    'لا يوجد رصيد مستحق لهذه الفاتورة.',
                ],
            ]);
        }
    }

    /**
     * Handle Paymob callback for portal invoice payments.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handleCallback(array $data): Payment
    {
        $hmac = $data['hmac'] ?? '';
        unset($data['hmac']);

        if (! $this->verifyHmac($data, $hmac)) {
            throw ValidationException::withMessages([
                'hmac' => [
                    'Invalid HMAC signature.',
                    'توقيع HMAC غير صالح.',
                ],
            ]);
        }

        $obj = $data['obj'] ?? $data;
        $orderId = (string) ($obj['order']['id'] ?? $obj['order_id'] ?? '');
        $success = (bool) ($obj['success'] ?? false);
        $amountCents = (int) ($obj['amount_cents'] ?? 0);

        // Extract invoice ID from merchant_order_id (format: INV-PAY-{id})
        $merchantOrderId = (string) ($obj['order']['merchant_order_id'] ?? '');
        $invoiceId = (int) str_replace('INV-PAY-', '', $merchantOrderId);

        $invoice = Invoice::withoutGlobalScopes()->findOrFail($invoiceId);

        if ($success) {
            $amount = round($amountCents / 100, 2);

            $payment = Payment::withoutGlobalScopes()->create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'date' => today()->toDateString(),
                'method' => 'credit_card',
                'reference' => "PAYMOB-{$orderId}",
                'notes' => 'دفع إلكتروني عبر بوابة العملاء',
                'created_by' => null,
            ]);

            // Update invoice amount_paid and status
            $newAmountPaid = bcadd((string) $invoice->amount_paid, (string) $amount, 2);
            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'status' => bccomp($newAmountPaid, (string) $invoice->total, 2) >= 0
                    ? InvoiceStatus::Paid
                    : InvoiceStatus::PartiallyPaid,
            ]);

            // Notify firm admin
            $admin = User::withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('role', 'admin')
                ->first();

            if ($admin) {
                $this->notificationService->sendPaymentReceived(
                    $admin->id,
                    (string) $amount,
                    $invoice->invoice_number,
                );
            }

            return $payment;
        }

        Log::warning('Portal payment failed.', [
            'invoice_id' => $invoiceId,
            'order_id' => $orderId,
            'error' => $obj['data']['message'] ?? 'unknown',
        ]);

        throw ValidationException::withMessages([
            'payment' => [
                'Payment failed. Please try again.',
                'فشل الدفع. يرجى المحاولة مرة أخرى.',
            ],
        ]);
    }

    private function isConfigured(): bool
    {
        return ! empty(config('services.paymob.api_key'))
            && ! empty(config('services.paymob.integration_id'));
    }

    private function authenticate(): string
    {
        $response = Http::post(self::BASE_URL . '/auth/tokens', [
            'api_key' => config('services.paymob.api_key'),
        ]);

        if (! $response->successful()) {
            Log::error('Paymob auth failed for portal payment.', ['status' => $response->status()]);

            throw ValidationException::withMessages([
                'gateway' => [
                    'Payment gateway authentication failed.',
                    'فشلت المصادقة مع بوابة الدفع.',
                ],
            ]);
        }

        return $response->json('token');
    }

    private function createOrder(string $authToken, int $amountCents, Invoice $invoice): int
    {
        $response = Http::post(self::BASE_URL . '/ecommerce/orders', [
            'auth_token' => $authToken,
            'delivery_needed' => false,
            'amount_cents' => $amountCents,
            'currency' => $invoice->currency ?? 'EGP',
            'merchant_order_id' => "INV-PAY-{$invoice->id}",
            'items' => [],
        ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'gateway' => [
                    'Failed to create payment order.',
                    'فشل في إنشاء طلب الدفع.',
                ],
            ]);
        }

        return $response->json('id');
    }

    private function generatePaymentKey(string $authToken, int $orderId, int $amountCents, Invoice $invoice): string
    {
        $client = $invoice->client;

        $response = Http::post(self::BASE_URL . '/acceptance/payment_keys', [
            'auth_token' => $authToken,
            'amount_cents' => $amountCents,
            'expiration' => 3600,
            'order_id' => $orderId,
            'billing_data' => [
                'first_name' => $client?->contact_person ?? $client?->name ?? 'Client',
                'last_name' => (string) $invoice->client_id,
                'email' => $client?->email ?? 'client@muhasebi.app',
                'phone_number' => $client?->phone ?? '+201000000000',
                'apartment' => 'N/A',
                'floor' => 'N/A',
                'street' => $client?->address ?? 'N/A',
                'building' => 'N/A',
                'shipping_method' => 'N/A',
                'postal_code' => 'N/A',
                'city' => $client?->city ?? 'N/A',
                'country' => 'EG',
                'state' => 'N/A',
            ],
            'currency' => $invoice->currency ?? 'EGP',
            'integration_id' => (int) config('services.paymob.integration_id'),
            'lock_order_when_paid' => true,
        ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'gateway' => [
                    'Failed to generate payment key.',
                    'فشل في توليد مفتاح الدفع.',
                ],
            ]);
        }

        return $response->json('token');
    }

    /**
     * Handle Fawry callback for portal invoice payments.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array{success: bool, status: string}
     */
    public function handleFawryCallback(array $data): array
    {
        $securityKey = IntegrationSetting::credential('fawry', 'security_key');

        $expectedSignature = hash('sha256',
            ($data['fawryRefNumber'] ?? '') .
            ($data['merchantRefNum'] ?? '') .
            ($data['paymentAmount'] ?? '') .
            ($data['orderAmount'] ?? '') .
            ($data['orderStatus'] ?? '') .
            ($data['paymentMethod'] ?? '') .
            $securityKey
        );

        if (! hash_equals($expectedSignature, $data['messageSignature'] ?? '')) {
            Log::warning('Fawry invoice callback: invalid signature', ['ref' => $data['merchantRefNum'] ?? 'unknown']);

            return ['success' => false, 'status' => 'invalid_signature'];
        }

        $merchantRef = $data['merchantRefNum'] ?? '';
        $status = $data['orderStatus'] ?? '';

        // Extract invoice ID from merchant ref (format: INV-FAWRY-{id}-{timestamp})
        if (! preg_match('/^INV-FAWRY-(\d+)-/', $merchantRef, $matches)) {
            // Not an invoice payment — delegate to subscription handler
            return ['success' => false, 'status' => 'not_invoice_payment'];
        }

        $invoiceId = (int) $matches[1];
        $invoice = Invoice::withoutGlobalScopes()->find($invoiceId);

        if (! $invoice) {
            Log::warning('Fawry invoice callback: invoice not found', ['ref' => $merchantRef, 'id' => $invoiceId]);

            return ['success' => false, 'status' => 'invoice_not_found'];
        }

        if ($status === 'PAID' || $status === 'DELIVERED') {
            $amount = (float) ($data['paymentAmount'] ?? $data['orderAmount'] ?? 0);

            $payment = Payment::withoutGlobalScopes()->create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'date' => today()->toDateString(),
                'method' => 'mobile_wallet',
                'reference' => 'FAWRY-' . ($data['fawryRefNumber'] ?? $merchantRef),
                'notes' => 'دفع إلكتروني عبر فوري من بوابة العملاء',
                'created_by' => null,
            ]);

            $newAmountPaid = bcadd((string) $invoice->amount_paid, (string) $amount, 2);
            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'status' => bccomp($newAmountPaid, (string) $invoice->total, 2) >= 0
                    ? InvoiceStatus::Paid
                    : InvoiceStatus::PartiallyPaid,
            ]);

            // Notify firm admin
            $admin = User::withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('role', 'admin')
                ->first();

            if ($admin) {
                $this->notificationService->sendPaymentReceived(
                    $admin->id,
                    (string) $amount,
                    $invoice->invoice_number,
                );
            }

            Log::info('Fawry invoice payment completed', [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'fawry_ref' => $data['fawryRefNumber'] ?? '',
            ]);

            return ['success' => true, 'status' => 'completed'];
        }

        if (in_array($status, ['EXPIRED', 'CANCELED', 'FAILED'])) {
            Log::info('Fawry invoice payment failed', ['invoice_id' => $invoiceId, 'status' => $status]);

            return ['success' => true, 'status' => 'failed'];
        }

        return ['success' => true, 'status' => 'pending'];
    }

    private function verifyHmac(array $data, string $hmac): bool
    {
        $hmacSecret = config('services.paymob.hmac_secret');

        if (! $hmacSecret) {
            return false;
        }

        $obj = $data['obj'] ?? $data;

        $fields = [
            'amount_cents' => $obj['amount_cents'] ?? '',
            'created_at' => $obj['created_at'] ?? '',
            'currency' => $obj['currency'] ?? '',
            'error_occured' => $obj['error_occured'] ?? '',
            'has_parent_transaction' => $obj['has_parent_transaction'] ?? '',
            'id' => $obj['id'] ?? '',
            'integration_id' => $obj['integration_id'] ?? '',
            'is_3d_secure' => $obj['is_3d_secure'] ?? '',
            'is_auth' => $obj['is_auth'] ?? '',
            'is_capture' => $obj['is_capture'] ?? '',
            'is_refunded' => $obj['is_refunded'] ?? '',
            'is_standalone_payment' => $obj['is_standalone_payment'] ?? '',
            'is_voided' => $obj['is_voided'] ?? '',
            'order.id' => $obj['order']['id'] ?? '',
            'owner' => $obj['owner'] ?? '',
            'pending' => $obj['pending'] ?? '',
            'source_data.pan' => $obj['source_data']['pan'] ?? '',
            'source_data.sub_type' => $obj['source_data']['sub_type'] ?? '',
            'source_data.type' => $obj['source_data']['type'] ?? '',
            'success' => $obj['success'] ?? '',
        ];

        $concatenated = implode('', array_values($fields));
        $calculated = hash_hmac('sha512', $concatenated, $hmacSecret);

        return hash_equals($calculated, $hmac);
    }
}
