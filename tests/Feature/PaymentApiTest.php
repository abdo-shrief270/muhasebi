<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Client\Models\Client;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

    // Create required accounts for payment GL posting
    $this->cashAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1111',
        'name_ar' => 'النقدية',
        'name_en' => 'Cash',
    ]);

    $this->bankAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1112',
        'name_ar' => 'البنك',
        'name_en' => 'Bank',
    ]);

    $this->arAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1121',
        'name_ar' => 'المدينون',
        'name_en' => 'Accounts Receivable',
    ]);

    // Create fiscal year and period
    $this->fiscalYear = FiscalYear::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    $this->fiscalPeriod = FiscalPeriod::factory()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_year_id' => $this->fiscalYear->id,
        'name' => 'March 2026',
        'period_number' => 3,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
    ]);
});

describe('POST /api/v1/payments', function (): void {

    it('records a payment on a sent invoice', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'total' => 1000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 500.00,
                'date' => '2026-03-15',
                'method' => PaymentMethod::Cash->value,
                'reference' => 'REF-001',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', '500.00')
            ->assertJsonPath('data.method', 'cash');
    });

    it('partial payment changes status to partially_paid', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'total' => 1000.00,
            'amount_paid' => 0,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 400.00,
                'date' => '2026-03-15',
                'method' => PaymentMethod::Cash->value,
            ]);

        $invoice->refresh();
        expect($invoice->status)->toBe(InvoiceStatus::PartiallyPaid);
        expect((float) $invoice->amount_paid)->toBe(400.00);
    });

    it('full payment changes status to paid', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'total' => 1000.00,
            'amount_paid' => 0,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 1000.00,
                'date' => '2026-03-15',
                'method' => PaymentMethod::BankTransfer->value,
            ]);

        $invoice->refresh();
        expect($invoice->status)->toBe(InvoiceStatus::Paid);
        expect((float) $invoice->amount_paid)->toBe(1000.00);
    });

    it('cannot overpay an invoice', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'total' => 1000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 1500.00,
                'date' => '2026-03-15',
                'method' => PaymentMethod::Cash->value,
            ]);

        $response->assertUnprocessable();
    });

    it('cannot pay a draft invoice', function (): void {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status' => InvoiceStatus::Draft,
            'total' => 1000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 500.00,
                'date' => '2026-03-15',
                'method' => PaymentMethod::Cash->value,
            ]);

        $response->assertUnprocessable();
    });

    it('cannot pay a cancelled invoice', function (): void {
        $invoice = Invoice::factory()->cancelled()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'total' => 1000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 500.00,
                'date' => '2026-03-15',
                'method' => PaymentMethod::Cash->value,
            ]);

        $response->assertUnprocessable();
    });
});

describe('DELETE /api/v1/payments/{payment}', function (): void {

    it('deletes a payment and recalculates amount_paid', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'total' => 1000.00,
            'amount_paid' => 500.00,
            'status' => InvoiceStatus::PartiallyPaid,
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'amount' => 500.00,
            'date' => '2026-03-15',
            'method' => PaymentMethod::Cash,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/payments/{$payment->id}");

        $response->assertOk();

        $invoice->refresh();
        expect((float) $invoice->amount_paid)->toBe(0.00);
        expect($invoice->status)->toBe(InvoiceStatus::Sent);
    });
});

describe('GET /api/v1/payments', function (): void {

    it('lists payments with filters', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        Payment::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/payments');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'invoice_id', 'amount', 'date', 'method']],
                'links',
                'meta',
            ]);
    });

    it('filters payments by invoice_id', function (): void {
        $invoice1 = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);
        $invoice2 = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
        ]);

        Payment::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice1->id,
        ]);
        Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice2->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/payments?invoice_id={$invoice1->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });
});
