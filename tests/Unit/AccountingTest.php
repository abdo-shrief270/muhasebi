<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;

describe('AccountType enum', function (): void {

    it('returns debit normal balance for asset type', function (): void {
        expect(AccountType::Asset->normalBalance())->toBe(NormalBalance::Debit);
    });

    it('returns debit normal balance for expense type', function (): void {
        expect(AccountType::Expense->normalBalance())->toBe(NormalBalance::Debit);
    });

    it('returns credit normal balance for liability type', function (): void {
        expect(AccountType::Liability->normalBalance())->toBe(NormalBalance::Credit);
    });

    it('returns credit normal balance for equity type', function (): void {
        expect(AccountType::Equity->normalBalance())->toBe(NormalBalance::Credit);
    });

    it('returns credit normal balance for revenue type', function (): void {
        expect(AccountType::Revenue->normalBalance())->toBe(NormalBalance::Credit);
    });
});

describe('JournalEntryStatus enum', function (): void {

    it('draft can be edited', function (): void {
        expect(JournalEntryStatus::Draft->canEdit())->toBeTrue();
    });

    it('posted cannot be edited', function (): void {
        expect(JournalEntryStatus::Posted->canEdit())->toBeFalse();
    });

    it('reversed cannot be edited', function (): void {
        expect(JournalEntryStatus::Reversed->canEdit())->toBeFalse();
    });

    it('draft can be posted', function (): void {
        expect(JournalEntryStatus::Draft->canPost())->toBeTrue();
    });

    it('posted cannot be posted again', function (): void {
        expect(JournalEntryStatus::Posted->canPost())->toBeFalse();
    });

    it('reversed cannot be posted', function (): void {
        expect(JournalEntryStatus::Reversed->canPost())->toBeFalse();
    });

    it('posted can be reversed', function (): void {
        expect(JournalEntryStatus::Posted->canReverse())->toBeTrue();
    });

    it('draft cannot be reversed', function (): void {
        expect(JournalEntryStatus::Draft->canReverse())->toBeFalse();
    });

    it('reversed cannot be reversed again', function (): void {
        expect(JournalEntryStatus::Reversed->canReverse())->toBeFalse();
    });
});

describe('JournalEntry model', function (): void {

    it('isBalanced returns true when total debit equals total credit', function (): void {
        $entry = JournalEntry::factory()->make([
            'total_debit' => 5000.00,
            'total_credit' => 5000.00,
        ]);

        expect($entry->isBalanced())->toBeTrue();
    });

    it('isBalanced returns false when total debit does not equal total credit', function (): void {
        $entry = JournalEntry::factory()->make([
            'total_debit' => 5000.00,
            'total_credit' => 3000.00,
        ]);

        expect($entry->isBalanced())->toBeFalse();
    });

    it('isDraft returns true for draft status', function (): void {
        $entry = JournalEntry::factory()->make(['status' => JournalEntryStatus::Draft]);
        expect($entry->isDraft())->toBeTrue();
    });

    it('isPosted returns true for posted status', function (): void {
        $entry = JournalEntry::factory()->posted()->make();
        expect($entry->isPosted())->toBeTrue();
    });

    it('isReversed returns true for reversed status', function (): void {
        $entry = JournalEntry::factory()->reversed()->make();
        expect($entry->isReversed())->toBeTrue();
    });
});

describe('JournalEntryLine model', function (): void {

    it('isDebit returns true when debit is greater than zero', function (): void {
        $line = JournalEntryLine::factory()->debit(1500)->make();
        expect($line->isDebit())->toBeTrue();
        expect($line->isCredit())->toBeFalse();
    });

    it('isCredit returns true when credit is greater than zero', function (): void {
        $line = JournalEntryLine::factory()->credit(2500)->make();
        expect($line->isCredit())->toBeTrue();
        expect($line->isDebit())->toBeFalse();
    });

    it('amount returns the debit value for debit lines', function (): void {
        $line = JournalEntryLine::factory()->debit(3000.50)->make();
        expect($line->amount())->toBe(3000.50);
    });

    it('amount returns the credit value for credit lines', function (): void {
        $line = JournalEntryLine::factory()->credit(4500.75)->make();
        expect($line->amount())->toBe(4500.75);
    });
});
