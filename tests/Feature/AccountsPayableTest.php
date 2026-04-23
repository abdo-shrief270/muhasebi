<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\AccountsPayable\Enums\BillStatus;
use App\Domain\AccountsPayable\Enums\BillType;
use App\Domain\AccountsPayable\Models\Bill;
use App\Domain\AccountsPayable\Models\BillLine;
use App\Domain\AccountsPayable\Models\BillPayment;
use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\AccountsPayable\Services\BillPaymentService;

test('vendor can be created with required fields', function () {
    $tenant = createTenant();
    app()->instance('tenant.id', $tenant->id);

    $vendor = Vendor::factory()->create([
        'tenant_id' => $tenant->id,
        'name_ar' => 'مورد اختبار',
        'name_en' => 'Test Vendor',
        'code' => 'V-0001',
    ]);

    expect($vendor)->toBeInstanceOf(Vendor::class)
        ->and($vendor->name_ar)->toBe('مورد اختبار')
        ->and($vendor->name_en)->toBe('Test Vendor')
        ->and($vendor->code)->toBe('V-0001')
        ->and($vendor->tenant_id)->toBe($tenant->id)
        ->and($vendor->is_active)->toBeTrue()
        ->and($vendor->currency)->toBe('EGP');
});

test('bill status transitions are correct', function () {
    expect(BillStatus::Draft->canApprove())->toBeTrue();
    expect(BillStatus::Draft->canEdit())->toBeTrue();
    expect(BillStatus::Draft->canPay())->toBeFalse();
    expect(BillStatus::Approved->canPay())->toBeTrue();
    expect(BillStatus::Approved->canEdit())->toBeFalse();
    expect(BillStatus::Paid->canCancel())->toBeFalse();
    expect(BillStatus::Cancelled->canApprove())->toBeFalse();
});

test('bill type labels are correct', function () {
    expect(BillType::Bill->label())->toBe('Bill');
    expect(BillType::Bill->labelAr())->toBe('فاتورة مشتريات');
    expect(BillType::DebitNote->label())->toBe('Debit Note');
    expect(BillType::CreditNote->label())->toBe('Credit Note');
});

test('bill line calculates totals correctly', function () {
    $line = new BillLine([
        'quantity' => '10',
        'unit_price' => '100.00',
        'discount_percent' => '5.00',
        'vat_rate' => '14.00',
        'wht_rate' => '3.00',
    ]);
    $line->calculateTotals();

    // Gross: 10 * 100 = 1000.00
    // Discount: 1000 * 5% = 50.00
    // Line total (after discount): 1000 - 50 = 950.00
    // VAT: 950 * 14% = 133.00
    // WHT: 950 * 3% = 28.50
    // Total: 950 + 133 - 28.50 = 1054.50
    expect($line->line_total)->toBe('950.00');
    expect($line->vat_amount)->toBe('133.00');
    expect($line->wht_amount)->toBe('28.50');
    expect($line->total)->toBe('1054.50');
});

describe('BillPaymentService input normalization', function (): void {
    beforeEach(function (): void {
        $this->tenant = createTenant();
        $this->admin = createAdminUser($this->tenant);
        actingAsUser($this->admin);

        // GL accounts required by record() — AP, cash, bank.
        Account::factory()->liability()->create(['tenant_id' => $this->tenant->id, 'code' => '2111', 'name_ar' => 'الدائنون']);
        Account::factory()->asset()->create(['tenant_id' => $this->tenant->id, 'code' => '1111', 'name_ar' => 'النقدية']);
        Account::factory()->asset()->create(['tenant_id' => $this->tenant->id, 'code' => '1112', 'name_ar' => 'البنك']);

        // Fiscal period covering today so the auto-generated JE can land.
        $fy = FiscalYear::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => (string) now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
        ]);
        FiscalPeriod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_id' => $fy->id,
            'name' => 'FY '.now()->year,
            'period_number' => 1,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
        ]);

        $this->vendor = Vendor::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->bill = Bill::factory()->approved()->create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'total' => '1000.00',
            'amount_paid' => '0.00',
        ]);
    });

    it('accepts FormRequest-style keys (payment_method / payment_date / check_number)', function (): void {
        $service = app(BillPaymentService::class);

        $payment = $service->record($this->bill, [
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'check',
            'check_number' => 'CHK-42',
            'reference' => 'REF-1',
        ]);

        expect($payment->amount)->toBe('500.00');
        expect($payment->payment_method->value)->toBe('check');
        expect($payment->check_number)->toBe('CHK-42');
        expect($this->bill->fresh()->status)->toBe(BillStatus::PartiallyPaid);
    });

    it('accepts internal-caller keys (method / date)', function (): void {
        $service = app(BillPaymentService::class);

        $payment = $service->record($this->bill, [
            'amount' => '1000.00',
            'date' => now()->toDateString(),
            'method' => 'bank_transfer',
        ]);

        expect($payment->payment_method->value)->toBe('bank_transfer');
        expect($this->bill->fresh()->status)->toBe(BillStatus::Paid);
    });

    it('rejects input missing both method and payment_method', function (): void {
        $service = app(BillPaymentService::class);

        $attempt = fn () => $service->record($this->bill, [
            'amount' => '500.00',
            'date' => now()->toDateString(),
        ]);

        expect($attempt)->toThrow(Illuminate\Validation\ValidationException::class);
        expect(BillPayment::query()->count())->toBe(0);
    });
});
