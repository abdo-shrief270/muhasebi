<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Services;

use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\FixedAssets\Enums\AssetStatus;
use App\Domain\FixedAssets\Enums\DepreciationMethod;
use App\Domain\FixedAssets\Enums\DisposalType;
use App\Domain\FixedAssets\Models\AssetDisposal;
use App\Domain\FixedAssets\Models\DepreciationEntry;
use App\Domain\FixedAssets\Models\FixedAsset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetDisposalService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * Dispose of a fixed asset.
     *
     * In a DB::transaction:
     *  1. Validate asset is active
     *  2. Run final depreciation up to disposal date if needed
     *  3. Calculate gain/loss = proceeds - book_value_at_disposal (bcmath)
     *  4. Create AssetDisposal record
     *  5. Post GL entry:
     *     - DEBIT cash/bank for proceeds (if sale)
     *     - DEBIT accumulated_depreciation_account for total accumulated
     *     - CREDIT asset_account for acquisition_cost
     *     - DEBIT/CREDIT disposal_account for loss/gain
     *  6. Update asset status to Disposed
     *  7. Return the disposal record
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function dispose(FixedAsset $asset, array $data): AssetDisposal
    {
        return DB::transaction(function () use ($asset, $data): AssetDisposal {
            $asset->load('category');

            // 1. Validate asset is active (or can be disposed)
            if (! $asset->status->canDispose()) {
                throw ValidationException::withMessages([
                    'status' => ["Asset cannot be disposed in its current status: {$asset->status->label()}."],
                ]);
            }

            $disposalDate = Carbon::parse($data['disposal_date']);
            $disposalType = $data['disposal_type'] instanceof DisposalType
                ? $data['disposal_type']
                : DisposalType::from($data['disposal_type']);
            $proceeds = (string) ($data['proceeds'] ?? '0.00');

            // 2. Run final depreciation up to disposal date if needed
            $this->runFinalDepreciation($asset, $disposalDate);

            // Refresh to get updated values after depreciation
            $asset->refresh();

            // 3. Calculate gain/loss: proceeds - book_value_at_disposal
            $bookValueAtDisposal = (string) $asset->book_value;
            $accumulatedAtDisposal = (string) $asset->accumulated_depreciation;
            $acquisitionCost = (string) $asset->acquisition_cost;
            $gainOrLoss = bcsub($proceeds, $bookValueAtDisposal, 2);

            // 4. Create AssetDisposal record
            $disposal = AssetDisposal::query()->create([
                'tenant_id' => app('tenant.id'),
                'fixed_asset_id' => $asset->id,
                'disposal_type' => $disposalType,
                'disposal_date' => $disposalDate->toDateString(),
                'disposal_amount' => $proceeds,
                'book_value_at_disposal' => $bookValueAtDisposal,
                'accumulated_depreciation_at_disposal' => $accumulatedAtDisposal,
                'gain_or_loss' => $gainOrLoss,
                'buyer_name' => $data['buyer_name'] ?? null,
                'created_by' => Auth::id(),
                'notes' => $data['notes'] ?? null,
            ]);

            // 5. Post GL entry
            $category = $asset->category;

            if ($category?->asset_account_id && $category?->accumulated_depreciation_account_id) {
                $currency = $asset->currency ?? 'EGP';
                $jeLines = [];

                // DEBIT cash/bank for proceeds (if sale with proceeds > 0)
                if (bccomp($proceeds, '0.00', 2) > 0) {
                    $cashAccountId = $data['cash_account_id']
                        ?? $this->resolveDefaultCashAccountId();

                    $jeLines[] = [
                        'account_id' => (int) $cashAccountId,
                        'debit' => (float) $proceeds,
                        'credit' => 0,
                        'currency' => $currency,
                        'description' => "حصيلة التصرف في أصل: {$asset->name_ar} ({$asset->code})",
                    ];
                }

                // DEBIT accumulated_depreciation_account for total accumulated depreciation
                if (bccomp($accumulatedAtDisposal, '0.00', 2) > 0) {
                    $jeLines[] = [
                        'account_id' => $category->accumulated_depreciation_account_id,
                        'debit' => (float) $accumulatedAtDisposal,
                        'credit' => 0,
                        'currency' => $currency,
                        'description' => "إلغاء مجمع إهلاك أصل: {$asset->name_ar} ({$asset->code})",
                    ];
                }

                // CREDIT asset_account for acquisition_cost
                $jeLines[] = [
                    'account_id' => $category->asset_account_id,
                    'debit' => 0,
                    'credit' => (float) $acquisitionCost,
                    'currency' => $currency,
                    'description' => "إلغاء تكلفة أصل: {$asset->name_ar} ({$asset->code})",
                ];

                // DEBIT/CREDIT disposal_account for loss/gain
                $disposalAccountId = $category->disposal_account_id;

                if ($disposalAccountId && bccomp($gainOrLoss, '0.00', 2) !== 0) {
                    $isGain = bccomp($gainOrLoss, '0.00', 2) > 0;
                    $absGainLoss = $isGain ? $gainOrLoss : bcmul($gainOrLoss, '-1', 2);

                    $jeLines[] = [
                        'account_id' => $disposalAccountId,
                        'debit' => $isGain ? 0 : (float) $absGainLoss,
                        'credit' => $isGain ? (float) $absGainLoss : 0,
                        'currency' => $currency,
                        'description' => $isGain
                            ? "أرباح التصرف في أصل: {$asset->name_ar} ({$asset->code})"
                            : "خسائر التصرف في أصل: {$asset->name_ar} ({$asset->code})",
                    ];
                }

                $journalEntry = $this->journalEntryService->create([
                    'date' => $disposalDate->toDateString(),
                    'description' => "التصرف في أصل ثابت: {$asset->name_ar} ({$asset->code})",
                    'reference' => $asset->code,
                    'lines' => $jeLines,
                ]);

                $this->journalEntryService->post($journalEntry);

                $disposal->update(['journal_entry_id' => $journalEntry->id]);
            }

            // 6. Update asset status to Disposed
            $asset->update([
                'status' => AssetStatus::Disposed,
            ]);

            // 7. Return the disposal record
            return $disposal->load(['fixedAsset', 'journalEntry']);
        });
    }

    /**
     * Paginated list of disposals with filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return AssetDisposal::query()
            ->with(['fixedAsset', 'fixedAsset.category'])
            ->when(
                isset($filters['disposal_type']),
                fn ($q) => $q->where(
                    'disposal_type',
                    $filters['disposal_type'] instanceof DisposalType
                        ? $filters['disposal_type']
                        : DisposalType::from($filters['disposal_type'])
                )
            )
            ->when(isset($filters['fixed_asset_id']), fn ($q) => $q->where('fixed_asset_id', $filters['fixed_asset_id']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('disposal_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('disposal_date', '<=', $filters['date_to']))
            ->when(
                isset($filters['search']),
                fn ($q) => $q->whereHas('fixedAsset', fn ($sub) => $sub->search($filters['search']))
            )
            ->orderBy('disposal_date', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Run final depreciation from the last depreciation date up to the disposal date.
     *
     * Calculates pro-rata depreciation for the partial period and creates a DepreciationEntry.
     *
     * @throws ValidationException
     */
    private function runFinalDepreciation(FixedAsset $asset, Carbon $disposalDate): void
    {
        // Skip if asset is already fully depreciated
        if ($asset->isFullyDepreciated()) {
            return;
        }

        // Determine the start of the final depreciation period
        $lastDepDate = $asset->last_depreciation_date
            ?? $asset->depreciation_start_date
            ?? $asset->acquisition_date;

        if (! $lastDepDate) {
            return;
        }

        $periodStart = Carbon::parse($lastDepDate);

        // If disposal date is not after the last depreciation date, no final depreciation needed
        if ($disposalDate->lte($periodStart)) {
            return;
        }

        // Calculate pro-rata days
        $daysInPeriod = $periodStart->diffInDays($disposalDate);

        if ($daysInPeriod <= 0) {
            return;
        }

        // Calculate daily depreciation rate based on method
        $bookValue = (string) $asset->book_value;
        $salvageValue = (string) $asset->salvage_value;
        $acquisitionCost = (string) $asset->acquisition_cost;
        $usefulLifeYears = (string) $asset->useful_life_years;

        if (bccomp($usefulLifeYears, '0', 2) === 0) {
            return;
        }

        $totalDaysInYear = '365';

        $annualDepreciation = match ($asset->depreciation_method) {
            DepreciationMethod::StraightLine => bcdiv(
                bcsub($acquisitionCost, $salvageValue, 2),
                $usefulLifeYears,
                10
            ),
            DepreciationMethod::DecliningBalance => bcdiv(
                bcmul($bookValue, '2', 10),
                $usefulLifeYears,
                10
            ),
            default => bcdiv(
                bcsub($acquisitionCost, $salvageValue, 2),
                $usefulLifeYears,
                10
            ),
        };

        // Pro-rata: annual * days / 365
        $depreciationAmount = bcdiv(
            bcmul($annualDepreciation, (string) $daysInPeriod, 10),
            $totalDaysInYear,
            2
        );

        // Ensure depreciation does not take book value below salvage value
        $maxDepreciation = bcsub($bookValue, $salvageValue, 2);

        if (bccomp($maxDepreciation, '0.00', 2) <= 0) {
            return;
        }

        if (bccomp($depreciationAmount, $maxDepreciation, 2) > 0) {
            $depreciationAmount = $maxDepreciation;
        }

        if (bccomp($depreciationAmount, '0.00', 2) <= 0) {
            return;
        }

        $newAccumulated = bcadd((string) $asset->accumulated_depreciation, $depreciationAmount, 2);
        $newBookValue = bcsub($bookValue, $depreciationAmount, 2);

        // Create the final depreciation entry
        DepreciationEntry::query()->create([
            'tenant_id' => app('tenant.id'),
            'fixed_asset_id' => $asset->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $disposalDate->toDateString(),
            'amount' => $depreciationAmount,
            'accumulated_after' => $newAccumulated,
            'book_value_after' => $newBookValue,
            'created_by' => Auth::id(),
            'notes' => 'إهلاك نهائي قبل التصرف في الأصل',
        ]);

        // Post GL entry for final depreciation if category accounts exist
        $category = $asset->category;

        if ($category?->depreciation_expense_account_id && $category?->accumulated_depreciation_account_id) {
            $currency = $asset->currency ?? 'EGP';

            $journalEntry = $this->journalEntryService->create([
                'date' => $disposalDate->toDateString(),
                'description' => "إهلاك نهائي - أصل: {$asset->name_ar} ({$asset->code})",
                'reference' => $asset->code,
                'lines' => [
                    [
                        'account_id' => $category->depreciation_expense_account_id,
                        'debit' => (float) $depreciationAmount,
                        'credit' => 0,
                        'currency' => $currency,
                        'description' => "مصروف إهلاك أصل: {$asset->name_ar}",
                    ],
                    [
                        'account_id' => $category->accumulated_depreciation_account_id,
                        'debit' => 0,
                        'credit' => (float) $depreciationAmount,
                        'currency' => $currency,
                        'description' => "مجمع إهلاك أصل: {$asset->name_ar}",
                    ],
                ],
            ]);

            $this->journalEntryService->post($journalEntry);
        }

        // Update the asset's running totals
        $asset->update([
            'accumulated_depreciation' => $newAccumulated,
            'book_value' => $newBookValue,
            'last_depreciation_date' => $disposalDate->toDateString(),
        ]);
    }

    /**
     * Resolve the default cash account ID for the current tenant.
     *
     * @throws ValidationException
     */
    private function resolveDefaultCashAccountId(): int
    {
        $code = config('accounting.default_accounts.cash');

        $account = \App\Domain\Accounting\Models\Account::query()
            ->forTenant((int) app('tenant.id'))
            ->where('code', $code)
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'account' => ["Required cash account with code '{$code}' not found. Please set up your chart of accounts."],
            ]);
        }

        return $account->id;
    }
}
