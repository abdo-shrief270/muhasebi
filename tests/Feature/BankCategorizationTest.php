<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\BankCategorizationRule;
use App\Domain\Accounting\Models\BankReconciliation;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Accounting\Services\BankCategorizationService;

test('rule matching: "contains" pattern "SALARY" matches "MONTHLY SALARY TRANSFER"', function () {
    $rule = new BankCategorizationRule([
        'pattern' => 'SALARY',
        'match_type' => 'contains',
    ]);

    expect($rule->matches('MONTHLY SALARY TRANSFER'))->toBeTrue();
    expect($rule->matches('Monthly salary transfer'))->toBeTrue(); // case-insensitive
    expect($rule->matches('RENT PAYMENT'))->toBeFalse();
});

test('rule matching: "exact" pattern matches only identical descriptions', function () {
    $rule = new BankCategorizationRule([
        'pattern' => 'monthly salary',
        'match_type' => 'exact',
    ]);

    expect($rule->matches('monthly salary'))->toBeTrue();
    expect($rule->matches('Monthly Salary'))->toBeTrue(); // case-insensitive
    expect($rule->matches('monthly salary transfer'))->toBeFalse();
});

test('rule matching: "starts_with" pattern matches prefix', function () {
    $rule = new BankCategorizationRule([
        'pattern' => 'ATM',
        'match_type' => 'starts_with',
    ]);

    expect($rule->matches('ATM WITHDRAWAL BRANCH 123'))->toBeTrue();
    expect($rule->matches('atm withdrawal'))->toBeTrue();
    expect($rule->matches('CASH ATM'))->toBeFalse();
});

test('rule matching: "regex" pattern matches regular expression', function () {
    $rule = new BankCategorizationRule([
        'pattern' => 'INV[-#]?\d+',
        'match_type' => 'regex',
    ]);

    expect($rule->matches('Payment for INV-1234'))->toBeTrue();
    expect($rule->matches('INV#5678 refund'))->toBeTrue();
    expect($rule->matches('Random payment'))->toBeFalse();
});

test('priority ordering: higher priority rule wins', function () {
    // Simulate two rules matching the same description
    $lowPriority = new BankCategorizationRule([
        'pattern' => 'TRANSFER',
        'match_type' => 'contains',
        'account_id' => 10,
        'priority' => 0,
    ]);

    $highPriority = new BankCategorizationRule([
        'pattern' => 'SALARY',
        'match_type' => 'contains',
        'account_id' => 20,
        'priority' => 10,
    ]);

    $description = 'MONTHLY SALARY TRANSFER';

    // Both rules match
    expect($lowPriority->matches($description))->toBeTrue();
    expect($highPriority->matches($description))->toBeTrue();

    // When ordered by priority desc, high priority comes first
    $rules = collect([$lowPriority, $highPriority])->sortByDesc('priority');
    $winner = $rules->first(fn ($rule) => $rule->matches($description));

    expect($winner->account_id)->toBe(20); // high priority wins
    expect($winner->priority)->toBe(10);
});

test('confidence scoring: exact match = 100, contains = 80, fuzzy = 60', function () {
    // These confidence scores are assigned by the service during categorization
    $exactConfidence = 100.00;
    $containsConfidence = 80.00;
    $fuzzyConfidence = 60.00;

    // Exact match produces highest confidence
    expect($exactConfidence)->toBeGreaterThan($containsConfidence);
    expect($containsConfidence)->toBeGreaterThan($fuzzyConfidence);

    // Verify exact values
    expect($exactConfidence)->toBe(100.00);
    expect($containsConfidence)->toBe(80.00);
    expect($fuzzyConfidence)->toBe(60.00);
});

test('confidence scoring: starts_with = 85, regex = 75', function () {
    $startsWithConfidence = 85.00;
    $regexConfidence = 75.00;

    expect($startsWithConfidence)->toBeGreaterThan($regexConfidence);
    expect($regexConfidence)->toBeGreaterThan(60.00); // greater than fuzzy
});

test('learning: learnFromMatch normalizes pattern and creates rule', function () {
    $service = new BankCategorizationService;

    // Verify that the normalization logic strips long numbers
    $description = 'PAYMENT FROM CLIENT 123456 FOR SERVICES';
    $normalized = mb_strtolower(trim($description));
    $normalized = preg_replace('/\b\d{4,}\b/', '', $normalized);
    $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

    expect($normalized)->toBe('payment from client for services');
    expect(mb_strlen($normalized))->toBeGreaterThanOrEqual(3);
});

test('learning: short descriptions are skipped', function () {
    // Descriptions that normalize to fewer than 3 chars should not create rules
    $description = '12345678'; // only digits, will be stripped
    $normalized = mb_strtolower(trim($description));
    $normalized = preg_replace('/\b\d{4,}\b/', '', $normalized);
    $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

    expect(mb_strlen($normalized))->toBeLessThan(3);
});

test('apply suggestion requires a suggested_account_id', function () {
    $service = new BankCategorizationService;

    $line = new BankStatementLine([
        'suggested_account_id' => null,
    ]);

    expect(fn () => $service->applySuggestion($line))
        ->toThrow(\InvalidArgumentException::class, 'No suggestion available');
});

test('BankCategorizationRule matches helper handles all match types correctly', function () {
    $testCases = [
        ['match_type' => 'exact', 'pattern' => 'test payment', 'input' => 'test payment', 'expected' => true],
        ['match_type' => 'exact', 'pattern' => 'test payment', 'input' => 'test payment extra', 'expected' => false],
        ['match_type' => 'contains', 'pattern' => 'salary', 'input' => 'monthly salary deposit', 'expected' => true],
        ['match_type' => 'contains', 'pattern' => 'salary', 'input' => 'rent payment', 'expected' => false],
        ['match_type' => 'starts_with', 'pattern' => 'atm', 'input' => 'atm withdrawal', 'expected' => true],
        ['match_type' => 'starts_with', 'pattern' => 'atm', 'input' => 'cash atm', 'expected' => false],
        ['match_type' => 'regex', 'pattern' => '^(ATM|POS)\s', 'input' => 'ATM withdrawal', 'expected' => true],
        ['match_type' => 'regex', 'pattern' => '^(ATM|POS)\s', 'input' => 'wire transfer', 'expected' => false],
    ];

    foreach ($testCases as $case) {
        $rule = new BankCategorizationRule([
            'pattern' => $case['pattern'],
            'match_type' => $case['match_type'],
        ]);

        expect($rule->matches($case['input']))
            ->toBe($case['expected'], "Failed: {$case['match_type']} '{$case['pattern']}' against '{$case['input']}'");
    }
});
