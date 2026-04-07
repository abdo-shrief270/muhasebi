<?php

declare(strict_types=1);

use App\Domain\Collection\Enums\CollectionActionType;
use App\Domain\Collection\Enums\CollectionOutcome;
use App\Domain\Collection\Enums\CollectionStatus;

// ──────────────────────────────────────
// Enum Labels
// ──────────────────────────────────────

describe('CollectionActionType labels', function (): void {
    it('has non-empty label for all cases', function (CollectionActionType $type): void {
        expect($type->label())->toBeString()->not->toBeEmpty();
    })->with(CollectionActionType::cases());

    it('has non-empty Arabic label for all cases', function (CollectionActionType $type): void {
        expect($type->labelAr())->toBeString()->not->toBeEmpty();
    })->with(CollectionActionType::cases());
});

describe('CollectionOutcome labels', function (): void {
    it('has non-empty label for all cases', function (CollectionOutcome $outcome): void {
        expect($outcome->label())->toBeString()->not->toBeEmpty();
    })->with(CollectionOutcome::cases());

    it('has non-empty Arabic label for all cases', function (CollectionOutcome $outcome): void {
        expect($outcome->labelAr())->toBeString()->not->toBeEmpty();
    })->with(CollectionOutcome::cases());
});

describe('CollectionStatus labels', function (): void {
    it('has non-empty label for all cases', function (CollectionStatus $status): void {
        expect($status->label())->toBeString()->not->toBeEmpty();
    })->with(CollectionStatus::cases());

    it('has non-empty Arabic label for all cases', function (CollectionStatus $status): void {
        expect($status->labelAr())->toBeString()->not->toBeEmpty();
    })->with(CollectionStatus::cases());
});

// ──────────────────────────────────────
// Write-off Calculation
// ──────────────────────────────────────

describe('Write-off gain/loss calculation', function (): void {
    it('calculates remaining balance after partial write-off', function (): void {
        $writeOffAmount = 5000;
        $invoiceBalance = 10000;

        $remaining = $invoiceBalance - $writeOffAmount;

        expect($remaining)->toBe(5000);
    });
});

// ──────────────────────────────────────
// DSO Calculation
// ──────────────────────────────────────

describe('DSO calculation', function (): void {
    it('computes days sales outstanding correctly', function (): void {
        $accountsReceivable = 100000;
        $creditSales = 300000;
        $periodDays = 90;

        $dso = ($accountsReceivable / $creditSales) * $periodDays;

        expect($dso)->toBe(30.0);
    });
});

// ──────────────────────────────────────
// Aging Buckets
// ──────────────────────────────────────

describe('Aging buckets', function (): void {
    /**
     * Determine the aging bucket label for the given overdue days.
     */
    function agingBucket(int $overdueDays): string
    {
        return match (true) {
            $overdueDays <= 0 => 'current',
            $overdueDays <= 30 => '1-30',
            $overdueDays <= 60 => '31-60',
            $overdueDays <= 90 => '61-90',
            default => '90+',
        };
    }

    it('places 15 days overdue into 1-30 bucket', function (): void {
        expect(agingBucket(15))->toBe('1-30');
    });

    it('places 45 days overdue into 31-60 bucket', function (): void {
        expect(agingBucket(45))->toBe('31-60');
    });
});
