<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;
use App\Domain\ClientPortal\Enums\DisputeStatus;
use App\Domain\ClientPortal\Enums\InstallmentStatus;
use App\Domain\ClientPortal\Enums\PaymentPlanStatus;
use App\Domain\ClientPortal\Models\InvoiceDispute;
use App\Domain\ClientPortal\Models\PaymentPlan;
use App\Domain\ClientPortal\Services\ClientPortalEnhancedService;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->clientUser = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->client->id,
        'role' => UserRole::Client,
    ]);
    $this->adminUser = createAdminUser($this->tenant);
    actingAsUser($this->clientUser);

    $this->invoice = Invoice::factory()->create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->client->id,
        'status' => InvoiceStatus::Sent,
        'total' => 10000.00,
        'amount_paid' => 0,
        'due_date' => now()->addDays(30),
    ]);
});

describe('Invoice Disputes', function (): void {

    it('creates a dispute for an invoice', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/portal/disputes', [
                'invoice_id' => $this->invoice->id,
                'subject' => 'Incorrect amount charged',
                'description' => 'The amount on the invoice does not match the agreed price.',
                'priority' => 'high',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.subject', 'Incorrect amount charged');
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.priority', 'high');

        $this->assertDatabaseHas('invoice_disputes', [
            'invoice_id' => $this->invoice->id,
            'client_id' => $this->client->id,
            'subject' => 'Incorrect amount charged',
            'status' => 'open',
        ]);
    });

    it('lists disputes for the authenticated client', function (): void {
        InvoiceDispute::create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $this->invoice->id,
            'client_id' => $this->client->id,
            'subject' => 'Test dispute',
            'description' => 'Test description',
            'status' => DisputeStatus::Open,
            'priority' => 'medium',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/disputes');

        $response->assertOk();
    });

    it('shows a single dispute', function (): void {
        $dispute = InvoiceDispute::create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $this->invoice->id,
            'client_id' => $this->client->id,
            'subject' => 'Test dispute',
            'description' => 'Test description',
            'status' => DisputeStatus::Open,
            'priority' => 'medium',
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/portal/disputes/{$dispute->id}");

        $response->assertOk();
        $response->assertJsonPath('data.subject', 'Test dispute');
    });

});

describe('Payment Plans', function (): void {

    it('creates a payment plan with correct installment calculation (10000/5 monthly = 2000 each)', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/portal/invoices/{$this->invoice->id}/payment-plan", [
                'installments' => 5,
                'frequency' => 'monthly',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.installments_count', 5);
        $response->assertJsonPath('data.total_amount', '10000.00');
        $response->assertJsonPath('data.installment_amount', '2000.00');

        $plan = PaymentPlan::first();
        expect($plan->installments)->toHaveCount(5);
        expect((float) $plan->installments->first()->amount)->toBe(2000.00);
    });

    it('marks installment as paid when payment is recorded', function (): void {
        // Create plan via service
        $service = app(ClientPortalEnhancedService::class);
        $plan = $service->createPaymentPlan($this->invoice->id, 5, 'monthly');

        $installment = $plan->installments->first();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/portal/installments/{$installment->id}/pay", []);

        $response->assertOk();

        $installment->refresh();
        expect($installment->status)->toBe(InstallmentStatus::Paid);
        expect($installment->paid_at)->not->toBeNull();
    });

    it('completes the plan when all installments are paid', function (): void {
        $service = app(ClientPortalEnhancedService::class);
        $plan = $service->createPaymentPlan($this->invoice->id, 3, 'monthly');

        // Pay all installments
        foreach ($plan->installments as $installment) {
            $service->recordInstallmentPayment($installment, []);
        }

        $plan->refresh();
        expect($plan->status)->toBe(PaymentPlanStatus::Completed);
        expect((float) $plan->remaining_amount)->toBe(0.00);
        expect($plan->paid_installments)->toBe(3);
    });

    it('lists payment plans for the authenticated client', function (): void {
        $service = app(ClientPortalEnhancedService::class);
        $service->createPaymentPlan($this->invoice->id, 4, 'weekly');

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/payment-plans');

        $response->assertOk();
    });

});

describe('Client Reports', function (): void {

    it('returns client report summary', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/portal/reports');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'outstanding_balance',
                'aging',
                'ytd_payments',
                'open_disputes_count',
                'active_payment_plans_count',
            ],
        ]);
    });

});
