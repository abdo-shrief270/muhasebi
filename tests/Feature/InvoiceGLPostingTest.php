<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceSettings;
use App\Domain\Client\Models\Client;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);

    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

    // Create required GL accounts
    $this->arAccount = Account::factory()->asset()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '1121',
        'name_ar' => 'المدينون',
        'name_en' => 'Accounts Receivable',
    ]);

    $this->revenueAccount = Account::factory()->revenue()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '4110',
        'name_ar' => 'الإيرادات',
        'name_en' => 'Revenue',
    ]);

    $this->vatAccount = Account::factory()->liability()->create([
        'tenant_id' => $this->tenant->id,
        'code' => '2131',
        'name_ar' => 'ضريبة القيمة المضافة',
        'name_en' => 'VAT Payable',
    ]);

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

    // Create invoice settings with account references
    InvoiceSettings::query()->create([
        'tenant_id' => $this->tenant->id,
        'ar_account_id' => $this->arAccount->id,
        'revenue_account_id' => $this->revenueAccount->id,
        'vat_account_id' => $this->vatAccount->id,
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

describe('POST /api/v1/invoices/{invoice}/post-to-gl', function (): void {

    it('creates a journal entry when posting invoice to GL', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-03-15',
            'subtotal' => 10000.00,
            'discount_amount' => 0,
            'vat_amount' => 1400.00,
            'total' => 11400.00,
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'quantity' => 1,
            'unit_price' => 10000.00,
            'line_total' => 10000.00,
            'vat_amount' => 1400.00,
            'total' => 11400.00,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/post-to-gl");

        $response->assertOk()
            ->assertJsonPath('data.journal_entry_id', fn ($val) => $val !== null);

        $invoice->refresh();
        expect($invoice->journal_entry_id)->not->toBeNull();

        $journalEntry = JournalEntry::query()->find($invoice->journal_entry_id);
        expect($journalEntry)->not->toBeNull();
        expect($journalEntry->status)->toBe(JournalEntryStatus::Posted);
    });

    it('creates a balanced journal entry (debit = credit)', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-03-15',
            'subtotal' => 5000.00,
            'discount_amount' => 0,
            'vat_amount' => 700.00,
            'total' => 5700.00,
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'quantity' => 1,
            'unit_price' => 5000.00,
            'line_total' => 5000.00,
            'vat_amount' => 700.00,
            'total' => 5700.00,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/post-to-gl");

        $invoice->refresh();
        $journalEntry = JournalEntry::query()->find($invoice->journal_entry_id);

        expect($journalEntry->isBalanced())->toBeTrue();
        expect((float) $journalEntry->total_debit)->toBe((float) $journalEntry->total_credit);
    });

    it('debits AR account for the invoice total', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-03-15',
            'subtotal' => 8000.00,
            'discount_amount' => 0,
            'vat_amount' => 1120.00,
            'total' => 9120.00,
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'quantity' => 1,
            'unit_price' => 8000.00,
            'line_total' => 8000.00,
            'vat_amount' => 1120.00,
            'total' => 9120.00,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/post-to-gl");

        $invoice->refresh();
        $journalEntry = JournalEntry::query()->find($invoice->journal_entry_id);

        $arLine = $journalEntry->lines()->where('account_id', $this->arAccount->id)->first();
        expect($arLine)->not->toBeNull();
        expect((float) $arLine->debit)->toBe(9120.00);
        expect((float) $arLine->credit)->toBe(0.00);
    });

    it('credits Revenue for subtotal and VAT Payable for vat_amount', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-03-15',
            'subtotal' => 6000.00,
            'discount_amount' => 0,
            'vat_amount' => 840.00,
            'total' => 6840.00,
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'quantity' => 1,
            'unit_price' => 6000.00,
            'line_total' => 6000.00,
            'vat_amount' => 840.00,
            'total' => 6840.00,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/post-to-gl");

        $invoice->refresh();
        $journalEntry = JournalEntry::query()->find($invoice->journal_entry_id);

        // Revenue line
        $revenueLine = $journalEntry->lines()->where('account_id', $this->revenueAccount->id)->first();
        expect($revenueLine)->not->toBeNull();
        expect((float) $revenueLine->credit)->toBe(6000.00);
        expect((float) $revenueLine->debit)->toBe(0.00);

        // VAT line
        $vatLine = $journalEntry->lines()->where('account_id', $this->vatAccount->id)->first();
        expect($vatLine)->not->toBeNull();
        expect((float) $vatLine->credit)->toBe(840.00);
        expect((float) $vatLine->debit)->toBe(0.00);
    });
});

describe('Cancel posted invoice reverses JE', function (): void {

    it('reverses the journal entry when a posted invoice is cancelled', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-03-15',
            'subtotal' => 5000.00,
            'discount_amount' => 0,
            'vat_amount' => 700.00,
            'total' => 5700.00,
        ]);

        InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'quantity' => 1,
            'unit_price' => 5000.00,
            'line_total' => 5000.00,
            'vat_amount' => 700.00,
            'total' => 5700.00,
        ]);

        // Post to GL first
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/post-to-gl");

        $invoice->refresh();
        $originalJeId = $invoice->journal_entry_id;
        expect($originalJeId)->not->toBeNull();

        // Cancel the invoice
        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel");

        // The original JE should be reversed
        $originalJe = JournalEntry::query()->find($originalJeId);
        expect($originalJe->status)->toBe(JournalEntryStatus::Reversed);

        // A reversal JE should exist
        $reversalJe = JournalEntry::query()
            ->where('reversal_of_id', $originalJeId)
            ->first();
        expect($reversalJe)->not->toBeNull();
        expect($reversalJe->status)->toBe(JournalEntryStatus::Posted);
    });
});

describe('Payment GL posting', function (): void {

    it('creates a journal entry when recording a payment (debit Cash/Bank, credit AR)', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-03-15',
            'total' => 5000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 5000.00,
                'date' => '2026-03-20',
                'method' => PaymentMethod::Cash->value,
            ]);

        $response->assertCreated();

        $paymentJeId = $response->json('data.journal_entry_id');
        expect($paymentJeId)->not->toBeNull();

        $je = JournalEntry::query()->find($paymentJeId);
        expect($je)->not->toBeNull();
        expect($je->isBalanced())->toBeTrue();

        // Debit Cash account
        $cashLine = $je->lines()->where('account_id', $this->cashAccount->id)->first();
        expect($cashLine)->not->toBeNull();
        expect((float) $cashLine->debit)->toBe(5000.00);

        // Credit AR account
        $arLine = $je->lines()->where('account_id', $this->arAccount->id)->first();
        expect($arLine)->not->toBeNull();
        expect((float) $arLine->credit)->toBe(5000.00);
    });

    it('uses bank account for bank transfer payments', function (): void {
        $invoice = Invoice::factory()->sent()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'date' => '2026-03-15',
            'total' => 3000.00,
            'amount_paid' => 0,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 3000.00,
                'date' => '2026-03-20',
                'method' => PaymentMethod::BankTransfer->value,
            ]);

        $response->assertCreated();

        $paymentJeId = $response->json('data.journal_entry_id');
        $je = JournalEntry::query()->find($paymentJeId);

        // Debit Bank account (not Cash)
        $bankLine = $je->lines()->where('account_id', $this->bankAccount->id)->first();
        expect($bankLine)->not->toBeNull();
        expect((float) $bankLine->debit)->toBe(3000.00);
    });
});
