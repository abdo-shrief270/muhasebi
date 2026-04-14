<?php

declare(strict_types=1);

use App\Domain\AccountsPayable\Enums\BillStatus;
use App\Domain\AccountsPayable\Enums\BillType;
use App\Domain\AccountsPayable\Models\BillLine;
use App\Domain\AccountsPayable\Models\Vendor;

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
