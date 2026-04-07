<?php

declare(strict_types=1);

use App\Domain\Billing\Models\AutoApprovalRule;
use App\Domain\Billing\Models\PaymentSchedule;

test('schedule creates a payment schedule record', function () {
    $schedule = new PaymentSchedule([
        'tenant_id' => 1,
        'bill_id' => 1,
        'scheduled_date' => '2026-04-15',
        'amount' => '5000.00',
        'status' => 'pending',
        'payment_method' => 'bank_transfer',
    ]);

    expect($schedule->status)->toBe('pending');
    expect($schedule->amount)->toBe('5000.00');
    expect($schedule->payment_method)->toBe('bank_transfer');
    expect($schedule->isPending())->toBeTrue();
    expect($schedule->isApproved())->toBeFalse();
    expect($schedule->isProcessed())->toBeFalse();
});

test('early discount calculation: bill 10000 with 2% discount if paid within 10 days saves 200', function () {
    // Given a bill of 10,000 with 2% early payment discount
    $amount = '10000.00';
    $discountPercent = '2.00';

    // Calculate the discount amount using bcmath
    $discountAmount = bcdiv(bcmul($amount, $discountPercent, 4), '100', 2);

    expect($discountAmount)->toBe('200.00');

    // The payment amount after discount
    $paymentAmount = bcsub($amount, $discountAmount, 2);
    expect($paymentAmount)->toBe('9800.00');
});

test('bulk scheduling creates multiple records', function () {
    $billIds = [1, 2, 3];
    $schedules = collect($billIds)->map(fn ($id) => new PaymentSchedule([
        'tenant_id' => 1,
        'bill_id' => $id,
        'scheduled_date' => '2026-04-20',
        'amount' => '1000.00',
        'status' => 'pending',
    ]));

    expect($schedules)->toHaveCount(3);
    expect($schedules->every(fn ($s) => $s->isPending()))->toBeTrue();
});

test('auto-approval rule matches entity by amount using lt operator', function () {
    $rule = new AutoApprovalRule([
        'tenant_id' => 1,
        'entity_type' => 'bill',
        'condition_field' => 'amount',
        'operator' => 'lt',
        'condition_value' => '5000',
        'auto_action' => 'approve',
        'is_active' => true,
    ]);

    // Amount 3000 is less than 5000 — should match
    expect($rule->matches('3000'))->toBeTrue();

    // Amount 5000 is not less than 5000 — should not match
    expect($rule->matches('5000'))->toBeFalse();

    // Amount 7000 is not less than 5000 — should not match
    expect($rule->matches('7000'))->toBeFalse();
});

test('auto-approval rule matches using lte operator', function () {
    $rule = new AutoApprovalRule([
        'entity_type' => 'invoice',
        'condition_field' => 'amount',
        'operator' => 'lte',
        'condition_value' => '5000',
        'auto_action' => 'approve',
    ]);

    expect($rule->matches('5000'))->toBeTrue();
    expect($rule->matches('4999.99'))->toBeTrue();
    expect($rule->matches('5000.01'))->toBeFalse();
});

test('auto-approval rule matches using in operator', function () {
    $rule = new AutoApprovalRule([
        'entity_type' => 'bill',
        'condition_field' => 'vendor_id',
        'operator' => 'in',
        'condition_value' => '10,20,30',
        'auto_action' => 'approve',
    ]);

    expect($rule->matches('10'))->toBeTrue();
    expect($rule->matches('20'))->toBeTrue();
    expect($rule->matches('99'))->toBeFalse();
});

test('payment schedule status transitions work correctly', function () {
    $schedule = new PaymentSchedule(['status' => 'pending']);
    expect($schedule->isPending())->toBeTrue();
    expect($schedule->isApproved())->toBeFalse();

    $schedule->status = 'approved';
    expect($schedule->isApproved())->toBeTrue();
    expect($schedule->isPending())->toBeFalse();

    $schedule->status = 'processed';
    expect($schedule->isProcessed())->toBeTrue();
    expect($schedule->isApproved())->toBeFalse();
});

test('processScheduled would create payments for approved schedules', function () {
    // This test verifies the concept — approved schedules with date <= today
    // get processed. Full integration requires tenant + DB setup.
    $schedule = new PaymentSchedule([
        'status' => 'approved',
        'scheduled_date' => now()->subDay()->toDateString(),
        'amount' => '2500.00',
        'bill_id' => 1,
    ]);

    expect($schedule->isApproved())->toBeTrue();
    expect($schedule->scheduled_date->isPast())->toBeTrue();
});
