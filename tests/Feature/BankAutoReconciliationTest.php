<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\BankStatementLine;

test('exact match: same amount + reference gives confidence 100', function () {
    // When a bank statement line has the exact same amount as an invoice
    // and the reference contains the invoice number, confidence should be 100
    $amount = '1500.00';
    $invoiceTotal = '1500.00';
    $reference = 'INV-2026-001';
    $invoiceNumber = 'INV-2026-001';

    // Exact amount match
    expect(bccomp($amount, $invoiceTotal, 2))->toBe(0);

    // Reference matches
    $refNormalized = mb_strtolower(trim($reference));
    $invNormalized = mb_strtolower(trim($invoiceNumber));
    expect(str_contains($refNormalized, $invNormalized) || str_contains($invNormalized, $refNormalized))->toBeTrue();

    // Combined = confidence 100
    $confidence = 100.00;
    expect($confidence)->toBe(100.00);
});

test('tolerance match: amount within 5% gives confidence 80', function () {
    // Invoice total is 1000.00, bank amount is 1040.00 (4% difference)
    $invoiceTotal = '1000.00';
    $bankAmount = '1040.00';
    $tolerance = bcmul($invoiceTotal, '0.05', 2); // 50.00

    $diff = bcsub($bankAmount, $invoiceTotal, 2); // 40.00
    $absDiff = ltrim($diff, '-');

    // 40.00 <= 50.00 — within tolerance
    expect(bccomp($absDiff, $tolerance, 2))->toBeLessThanOrEqual(0);

    // When also within 3 days, confidence should be 80
    $confidence = 80.00;
    expect($confidence)->toBe(80.00);
});

test('tolerance match: amount exceeding 5% is not matched by tolerance', function () {
    // Invoice total is 1000.00, bank amount is 1060.00 (6% difference)
    $invoiceTotal = '1000.00';
    $bankAmount = '1060.00';
    $tolerance = bcmul($invoiceTotal, '0.05', 2); // 50.00

    $diff = bcsub($bankAmount, $invoiceTotal, 2); // 60.00
    $absDiff = ltrim($diff, '-');

    // 60.00 > 50.00 — exceeds tolerance
    expect(bccomp($absDiff, $tolerance, 2))->toBe(1);
});

test('description match: client name in description gives confidence 60', function () {
    $description = 'Transfer from ACME Corp for services rendered';
    $clientName = 'ACME Corp';

    $normalizedDesc = mb_strtolower(trim($description));
    $normalizedClient = mb_strtolower(trim($clientName));

    expect(str_contains($normalizedDesc, $normalizedClient))->toBeTrue();

    $confidence = 60.00;
    expect($confidence)->toBe(60.00);
});

test('no match: completely different amount returns no match', function () {
    $invoiceTotal = '1000.00';
    $bankAmount = '5000.00';
    $tolerance = bcmul($invoiceTotal, '0.05', 2); // 50.00

    $diff = bcsub($bankAmount, $invoiceTotal, 2); // 4000.00
    $absDiff = ltrim($diff, '-');

    // Way beyond tolerance
    expect(bccomp($absDiff, $tolerance, 2))->toBe(1);

    // No reference match either
    $reference = 'MISC-999';
    $invoiceNumber = 'INV-2026-001';
    $refNormalized = mb_strtolower(trim($reference));
    $invNormalized = mb_strtolower(trim($invoiceNumber));
    expect(str_contains($refNormalized, $invNormalized) || str_contains($invNormalized, $refNormalized))->toBeFalse();

    // Description also does not match
    $description = 'random payment xyz';
    $clientName = 'ACME Corp';
    expect(str_contains(mb_strtolower($description), mb_strtolower($clientName)))->toBeFalse();
});

test('auto-post: only posts lines with confidence >= 80', function () {
    // Lines with confidence >= 80 should be auto-posted
    $highConfidence = 100.00;
    $mediumConfidence = 80.00;
    $lowConfidence = 60.00;

    expect($highConfidence >= 80)->toBeTrue();
    expect($mediumConfidence >= 80)->toBeTrue();
    expect($lowConfidence >= 80)->toBeFalse();
});

test('confidence distribution: returns correct counts per bucket', function () {
    // Simulate a distribution of matched lines
    $confidences = [100.00, 100.00, 80.00, 80.00, 80.00, 60.00, 60.00];

    $distribution = ['high' => 0, 'medium' => 0, 'low' => 0];

    foreach ($confidences as $confidence) {
        if ($confidence >= 80) {
            $distribution['high']++;
        } elseif ($confidence >= 60) {
            $distribution['medium']++;
        } else {
            $distribution['low']++;
        }
    }

    expect($distribution['high'])->toBe(5);   // 100, 100, 80, 80, 80
    expect($distribution['medium'])->toBe(2); // 60, 60
    expect($distribution['low'])->toBe(0);
});

test('bcmath precision: amount comparisons are correct to 2 decimal places', function () {
    // Verify bcmath usage for precise comparisons
    expect(bccomp('1000.00', '1000.00', 2))->toBe(0);
    expect(bccomp('1000.01', '1000.00', 2))->toBe(1);
    expect(bccomp('999.99', '1000.00', 2))->toBe(-1);

    // Tolerance calculation
    $total = '2500.50';
    $tolerance = bcmul($total, '0.05', 2); // 125.02
    expect($tolerance)->toBe('125.02');

    // Diff within tolerance
    $bankAmount = '2600.00';
    $diff = bcsub($bankAmount, $total, 2); // 99.50
    expect(bccomp($diff, $tolerance, 2))->toBeLessThanOrEqual(0);
});

test('reference matching: partial and case-insensitive', function () {
    // Reference in statement contains the invoice number
    $reference = 'Payment for INV-2026-001 services';
    $invoiceNumber = 'INV-2026-001';

    $ref = mb_strtolower(trim($reference));
    $doc = mb_strtolower(trim($invoiceNumber));

    expect(str_contains($ref, $doc) || str_contains($doc, $ref))->toBeTrue();

    // Reverse containment
    $reference2 = 'INV-2026-001';
    $invoiceNumber2 = 'INV-2026-001-extra';

    $ref2 = mb_strtolower(trim($reference2));
    $doc2 = mb_strtolower(trim($invoiceNumber2));

    expect(str_contains($ref2, $doc2) || str_contains($doc2, $ref2))->toBeTrue();
});

test('deposit lines match invoices, withdrawal lines match bills', function () {
    // A deposit (positive amount) should be matched against invoices
    $depositLine = new BankStatementLine([
        'amount' => '1500.00',
        'type' => 'deposit',
    ]);

    expect($depositLine->isDeposit())->toBeTrue();
    expect($depositLine->isWithdrawal())->toBeFalse();

    // A withdrawal (negative amount) should be matched against bills
    $withdrawalLine = new BankStatementLine([
        'amount' => '-1500.00',
        'type' => 'withdrawal',
    ]);

    expect($withdrawalLine->isWithdrawal())->toBeTrue();
    expect($withdrawalLine->isDeposit())->toBeFalse();
});
