<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Services;

use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\FixedAssets\Enums\AssetStatus;
use App\Domain\FixedAssets\Enums\DepreciationMethod;
use App\Domain\FixedAssets\Models\DepreciationEntry;
use App\Domain\FixedAssets\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DepreciationService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    // ──────────────────────────────────────
    // 1. Calculate monthly depreciation
    // ──────────────────────────────────────

    /**
     * Calculate the monthly depreciation amount for an asset.
     */
    public function calculateMonthly(FixedAsset $asset): string
    {
        return match ($asset->depreciation_method) {
            DepreciationMethod::StraightLine => $this->straightLine($asset),
            DepreciationMethod::DecliningBalance => $this->decliningBalance($asset),
            default => '0.00',
        };
    }

    private function straightLine(FixedAsset $asset): string
    {
        $depreciableAmount = bcsub((string) $asset->acquisition_cost, (string) $asset->salvage_value, 2);
        $totalMonths = bcmul((string) $asset->useful_life_years, '12', 0);

        if (bccomp($totalMonths, '0', 0) <= 0) {
            return '0.00';
        }

        return bcdiv($depreciableAmount, $totalMonths, 2);
    }

    private function decliningBalance(FixedAsset $asset): string
    {
        $rate = bcdiv('2', (string) $asset->useful_life_years, 6);
        $annualAmount = bcmul((string) $asset->book_value, $rate, 2);

        // Don't depreciate below salvage
        $maxDepreciable = bcsub((string) $asset->book_value, (string) $asset->salvage_value, 2);

        if (bccomp($annualAmount, $maxDepreciable, 2) > 0) {
            $annualAmount = $maxDepreciable;
        }

        return bcdiv($annualAmount, '12', 2);
    }

    // ──────────────────────────────────────
    // 2. Run monthly depreciation
    // ──────────────────────────────────────

    /**
     * Run depreciation for all active assets for a tenant for a given month.
     *
     * @return array{count: int, total_amount: string}
     */
    public function runMonthly(int $tenantId, string $periodEnd): array
    {
        return DB::transaction(function () use ($tenantId, $periodEnd): array {
            $assets = FixedAsset::query()
                ->forTenant($tenantId)
                ->active()
                ->with('category')
                ->get()
                ->reject(fn (FixedAsset $asset) => $asset->isFullyDepreciated());

            $count = 0;
            $totalAmount = '0.00';

            foreach ($assets as $asset) {
                $monthlyAmount = $this->calculateMonthly($asset);

                if (bccomp($monthlyAmount, '0.00', 2) <= 0) {
                    continue;
                }

                // Don't exceed remaining depreciable amount
                $remaining = bcsub(
                    bcsub((string) $asset->acquisition_cost, (string) $asset->salvage_value, 2),
                    (string) $asset->accumulated_depreciation,
                    2
                );

                if (bccomp($remaining, '0.00', 2) <= 0) {
                    continue;
                }

                if (bccomp($monthlyAmount, $remaining, 2) > 0) {
                    $monthlyAmount = $remaining;
                }

                $newAccumulated = bcadd((string) $asset->accumulated_depreciation, $monthlyAmount, 2);
                $newBookValue = bcsub((string) $asset->acquisition_cost, $newAccumulated, 2);

                // Post GL journal entry
                $journalEntry = $this->journalEntryService->create([
                    'date' => $periodEnd,
                    'description' => "Depreciation - {$asset->name_en} ({$asset->code})",
                    'reference' => "DEP-{$asset->code}-{$periodEnd}",
                    'lines' => [
                        [
                            'account_id' => $asset->category->depreciation_expense_account_id,
                            'debit' => $monthlyAmount,
                            'credit' => '0',
                            'description' => "Depreciation expense - {$asset->name_en}",
                        ],
                        [
                            'account_id' => $asset->category->accumulated_depreciation_account_id,
                            'debit' => '0',
                            'credit' => $monthlyAmount,
                            'description' => "Accumulated depreciation - {$asset->name_en}",
                        ],
                    ],
                ]);

                // Auto-post the journal entry
                $this->journalEntryService->post($journalEntry);

                // Create depreciation entry record
                DepreciationEntry::query()->create([
                    'tenant_id' => $tenantId,
                    'fixed_asset_id' => $asset->id,
                    'journal_entry_id' => $journalEntry->id,
                    'period_end' => $periodEnd,
                    'amount' => $monthlyAmount,
                    'accumulated_after' => $newAccumulated,
                    'book_value_after' => $newBookValue,
                ]);

                // Update asset balances
                $asset->update([
                    'accumulated_depreciation' => $newAccumulated,
                    'book_value' => $newBookValue,
                    'last_depreciation_date' => $periodEnd,
                ]);

                // Mark fully depreciated if applicable
                if (bccomp($newBookValue, (string) $asset->salvage_value, 2) <= 0) {
                    $asset->update(['status' => AssetStatus::FullyDepreciated]);
                }

                $count++;
                $totalAmount = bcadd($totalAmount, $monthlyAmount, 2);
            }

            return [
                'count' => $count,
                'total_amount' => $totalAmount,
            ];
        });
    }

    // ──────────────────────────────────────
    // 3. Generate depreciation schedule
    // ──────────────────────────────────────

    /**
     * Generate full depreciation schedule (forecast) for an asset.
     *
     * @return array<int, array{period: string, amount: string, accumulated: string, book_value: string}>
     */
    public function schedule(FixedAsset $asset): array
    {
        $schedule = [];
        $totalMonths = (int) bcmul((string) $asset->useful_life_years, '12', 0);
        $accumulated = '0.00';
        $bookValue = (string) $asset->acquisition_cost;
        $salvage = (string) $asset->salvage_value;
        $startDate = Carbon::parse($asset->acquisition_date)->startOfMonth();

        for ($month = 1; $month <= $totalMonths; $month++) {
            $periodDate = $startDate->copy()->addMonths($month)->endOfMonth();

            if ($asset->depreciation_method === DepreciationMethod::StraightLine) {
                $amount = $this->straightLine($asset);
            } else {
                // Declining balance uses current book value
                $rate = bcdiv('2', (string) $asset->useful_life_years, 6);
                $annualAmount = bcmul($bookValue, $rate, 2);
                $maxDepreciable = bcsub($bookValue, $salvage, 2);

                if (bccomp($annualAmount, $maxDepreciable, 2) > 0) {
                    $annualAmount = $maxDepreciable;
                }

                $amount = bcdiv($annualAmount, '12', 2);
            }

            // Don't exceed remaining depreciable amount
            $remaining = bcsub(
                bcsub((string) $asset->acquisition_cost, $salvage, 2),
                $accumulated,
                2
            );

            if (bccomp($remaining, '0.00', 2) <= 0) {
                break;
            }

            if (bccomp($amount, $remaining, 2) > 0) {
                $amount = $remaining;
            }

            $accumulated = bcadd($accumulated, $amount, 2);
            $bookValue = bcsub((string) $asset->acquisition_cost, $accumulated, 2);

            $schedule[] = [
                'period' => $periodDate->format('Y-m-d'),
                'amount' => $amount,
                'accumulated' => $accumulated,
                'book_value' => $bookValue,
            ];

            // Stop if fully depreciated
            if (bccomp($bookValue, $salvage, 2) <= 0) {
                break;
            }
        }

        return $schedule;
    }

    // ──────────────────────────────────────
    // 4. Reverse a depreciation entry
    // ──────────────────────────────────────

    /**
     * Reverse a depreciation entry: restore asset balances, reverse GL, delete record.
     */
    public function reverseEntry(DepreciationEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $entry->load(['fixedAsset', 'journalEntry']);

            $asset = $entry->fixedAsset;

            // Restore asset balances
            $newAccumulated = bcsub(
                (string) $asset->accumulated_depreciation,
                (string) $entry->amount,
                2
            );
            $newBookValue = bcadd(
                (string) $asset->book_value,
                (string) $entry->amount,
                2
            );

            $asset->update([
                'accumulated_depreciation' => $newAccumulated,
                'book_value' => $newBookValue,
            ]);

            // If asset was fully depreciated, reactivate it
            if ($asset->status === AssetStatus::FullyDepreciated) {
                $asset->update(['status' => AssetStatus::Active]);
            }

            // Reverse the GL journal entry
            if ($entry->journalEntry) {
                $this->journalEntryService->reverse($entry->journalEntry);
            }

            // Delete the depreciation entry record
            $entry->delete();
        });
    }
}
