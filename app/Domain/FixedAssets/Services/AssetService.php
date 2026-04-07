<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\FixedAssets\Enums\AssetStatus;
use App\Domain\FixedAssets\Models\AssetCategory;
use App\Domain\FixedAssets\Models\FixedAsset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * Paginated list of fixed assets with eager-loaded category, filtered by status/category/search.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return FixedAsset::query()
            ->with(['category'])
            ->when(
                isset($filters['search']),
                fn ($q) => $q->search($filters['search'])
            )
            ->when(
                isset($filters['status']),
                fn ($q) => $q->where(
                    'status',
                    $filters['status'] instanceof AssetStatus
                        ? $filters['status']
                        : AssetStatus::from($filters['status'])
                )
            )
            ->when(
                isset($filters['category_id']),
                fn ($q) => $q->byCategory((int) $filters['category_id'])
            )
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a fixed asset with optional GL acquisition entry.
     *
     * Wraps everything in a DB transaction. Sets book_value = acquisition_cost.
     * If the category has GL accounts configured, posts:
     *   DEBIT  asset_account (from category)
     *   CREDIT cash/bank/AP (from data['credit_account_id'])
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): FixedAsset
    {
        return DB::transaction(function () use ($data): FixedAsset {
            $category = AssetCategory::query()->findOrFail($data['category_id']);

            $acquisitionCost = (string) $data['acquisition_cost'];
            $salvageValue = (string) ($data['salvage_value'] ?? '0.00');

            $asset = FixedAsset::query()->create([
                'tenant_id' => app('tenant.id'),
                'category_id' => $data['category_id'],
                'vendor_id' => $data['vendor_id'] ?? null,
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'] ?? null,
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'location' => $data['location'] ?? null,
                'status' => AssetStatus::Active,
                'depreciation_method' => $data['depreciation_method'] ?? $category->depreciation_method,
                'acquisition_date' => $data['acquisition_date'],
                'depreciation_start_date' => $data['depreciation_start_date'] ?? $data['acquisition_date'],
                'acquisition_cost' => $acquisitionCost,
                'salvage_value' => $salvageValue,
                'useful_life_years' => $data['useful_life_years'] ?? $category->default_useful_life_years,
                'accumulated_depreciation' => '0.00',
                'book_value' => $acquisitionCost,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'created_by' => Auth::id(),
                'notes' => $data['notes'] ?? null,
            ]);

            // Post acquisition GL entry if category has accounts configured and a credit account is provided
            $creditAccountId = $data['credit_account_id'] ?? null;

            if ($creditAccountId && $category->asset_account_id) {
                $currency = $data['currency'] ?? 'EGP';

                $journalEntry = $this->journalEntryService->create([
                    'date' => $data['acquisition_date'],
                    'description' => "اقتناء أصل ثابت: {$asset->name_ar} ({$asset->code})",
                    'reference' => $asset->code,
                    'lines' => [
                        [
                            'account_id' => $category->asset_account_id,
                            'debit' => (float) $acquisitionCost,
                            'credit' => 0,
                            'currency' => $currency,
                            'description' => "اقتناء أصل ثابت: {$asset->name_ar}",
                        ],
                        [
                            'account_id' => (int) $creditAccountId,
                            'debit' => 0,
                            'credit' => (float) $acquisitionCost,
                            'currency' => $currency,
                            'description' => "اقتناء أصل ثابت: {$asset->name_ar}",
                        ],
                    ],
                ]);

                $this->journalEntryService->post($journalEntry);

                $asset->update(['acquisition_journal_id' => $journalEntry->id]);
            }

            return $asset->load('category');
        });
    }

    /**
     * Update a fixed asset.
     *
     * Only allowed if no depreciation entries exist (financial fields) or only non-financial fields.
     * Asset must be active.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        if ($asset->status !== AssetStatus::Active) {
            throw ValidationException::withMessages([
                'status' => ['Only active assets can be updated.'],
            ]);
        }

        $hasDepreciation = $asset->depreciationEntries()->exists();

        $financialFields = [
            'acquisition_cost', 'salvage_value', 'useful_life_years',
            'depreciation_method', 'depreciation_start_date', 'acquisition_date',
        ];

        if ($hasDepreciation) {
            $attemptedFinancial = array_intersect_key($data, array_flip($financialFields));

            if (! empty($attemptedFinancial)) {
                throw ValidationException::withMessages([
                    'depreciation' => ['Cannot update financial fields after depreciation has been recorded. Fields: ' . implode(', ', array_keys($attemptedFinancial))],
                ]);
            }
        }

        $allowedFields = [
            'name_ar', 'name_en', 'description', 'serial_number', 'location',
            'notes', 'responsible_user_id', 'vendor_id', 'category_id',
        ];

        if (! $hasDepreciation) {
            $allowedFields = array_merge($allowedFields, $financialFields);
        }

        $updateData = array_intersect_key($data, array_flip($allowedFields));

        // If acquisition_cost is being updated, recalculate book_value
        if (isset($updateData['acquisition_cost'])) {
            $updateData['book_value'] = $updateData['acquisition_cost'];
        }

        $asset->update($updateData);

        return $asset->refresh()->load('category');
    }

    /**
     * Show a fixed asset with all related data.
     */
    public function show(FixedAsset $asset): FixedAsset
    {
        return $asset->load(['category', 'depreciationEntries', 'disposals', 'vendor']);
    }

    /**
     * Soft-delete a fixed asset. Only allowed if no depreciation entries exist.
     *
     * @throws ValidationException
     */
    public function delete(FixedAsset $asset): void
    {
        if ($asset->depreciationEntries()->exists()) {
            throw ValidationException::withMessages([
                'depreciation' => ['Cannot delete an asset that has depreciation entries. Dispose of it instead.'],
            ]);
        }

        $asset->delete();
    }

    /**
     * Single-asset register report: acquisition info, all depreciation entries, current book value.
     *
     * @return array<string, mixed>
     */
    public function register(FixedAsset $asset): array
    {
        $asset->load(['category', 'depreciationEntries' => fn ($q) => $q->orderBy('period_end')]);

        return [
            'asset_id' => $asset->id,
            'code' => $asset->code,
            'name_ar' => $asset->name_ar,
            'name_en' => $asset->name_en,
            'category' => $asset->category?->name_ar,
            'acquisition_date' => $asset->acquisition_date?->toDateString(),
            'acquisition_cost' => (string) $asset->acquisition_cost,
            'salvage_value' => (string) $asset->salvage_value,
            'useful_life_years' => (string) $asset->useful_life_years,
            'depreciation_method' => $asset->depreciation_method?->value,
            'accumulated_depreciation' => (string) $asset->accumulated_depreciation,
            'book_value' => (string) $asset->book_value,
            'status' => $asset->status->value,
            'depreciation_entries' => $asset->depreciationEntries->map(fn ($entry) => [
                'period_start' => $entry->period_start->toDateString(),
                'period_end' => $entry->period_end->toDateString(),
                'amount' => (string) $entry->amount,
                'accumulated_after' => (string) $entry->accumulated_after,
                'book_value_after' => (string) $entry->book_value_after,
            ])->toArray(),
        ];
    }

    /**
     * Full asset register report — all assets with current book values, grouped by category.
     * All arithmetic uses bcmath.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function assetRegister(array $filters = []): array
    {
        $assets = FixedAsset::query()
            ->with(['category'])
            ->when(isset($filters['status']), fn ($q) => $q->where(
                'status',
                $filters['status'] instanceof AssetStatus
                    ? $filters['status']
                    : AssetStatus::from($filters['status'])
            ))
            ->when(isset($filters['category_id']), fn ($q) => $q->byCategory((int) $filters['category_id']))
            ->orderBy('category_id')
            ->orderBy('code')
            ->get();

        $grouped = [];
        $totalAcquisition = '0.00';
        $totalAccumulated = '0.00';
        $totalBookValue = '0.00';

        foreach ($assets as $asset) {
            $categoryName = $asset->category?->name_ar ?? 'غير مصنف';
            $categoryId = $asset->category_id;

            if (! isset($grouped[$categoryId])) {
                $grouped[$categoryId] = [
                    'category_id' => $categoryId,
                    'category_name' => $categoryName,
                    'total_acquisition_cost' => '0.00',
                    'total_accumulated_depreciation' => '0.00',
                    'total_book_value' => '0.00',
                    'assets' => [],
                ];
            }

            $grouped[$categoryId]['assets'][] = [
                'id' => $asset->id,
                'code' => $asset->code,
                'name_ar' => $asset->name_ar,
                'status' => $asset->status->value,
                'acquisition_date' => $asset->acquisition_date?->toDateString(),
                'acquisition_cost' => (string) $asset->acquisition_cost,
                'accumulated_depreciation' => (string) $asset->accumulated_depreciation,
                'book_value' => (string) $asset->book_value,
            ];

            $grouped[$categoryId]['total_acquisition_cost'] = bcadd(
                $grouped[$categoryId]['total_acquisition_cost'],
                (string) $asset->acquisition_cost,
                2
            );
            $grouped[$categoryId]['total_accumulated_depreciation'] = bcadd(
                $grouped[$categoryId]['total_accumulated_depreciation'],
                (string) $asset->accumulated_depreciation,
                2
            );
            $grouped[$categoryId]['total_book_value'] = bcadd(
                $grouped[$categoryId]['total_book_value'],
                (string) $asset->book_value,
                2
            );

            $totalAcquisition = bcadd($totalAcquisition, (string) $asset->acquisition_cost, 2);
            $totalAccumulated = bcadd($totalAccumulated, (string) $asset->accumulated_depreciation, 2);
            $totalBookValue = bcadd($totalBookValue, (string) $asset->book_value, 2);
        }

        return [
            'categories' => array_values($grouped),
            'totals' => [
                'total_acquisition_cost' => $totalAcquisition,
                'total_accumulated_depreciation' => $totalAccumulated,
                'total_book_value' => $totalBookValue,
            ],
        ];
    }

    /**
     * Asset roll-forward report: opening balance + additions - disposals - depreciation = closing balance,
     * per category. Date range filter. All bcmath.
     *
     * @param  array<string, mixed>  $filters  Must include 'date_from' and 'date_to'
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function rollForward(array $filters = []): array
    {
        if (! isset($filters['date_from'], $filters['date_to'])) {
            throw ValidationException::withMessages([
                'date_range' => ['Both date_from and date_to are required for roll-forward report.'],
            ]);
        }

        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];

        $categories = AssetCategory::query()
            ->with(['assets' => fn ($q) => $q->withTrashed()])
            ->when(isset($filters['category_id']), fn ($q) => $q->where('id', $filters['category_id']))
            ->get();

        $report = [];
        $grandTotals = [
            'opening_cost' => '0.00',
            'opening_accumulated' => '0.00',
            'opening_book_value' => '0.00',
            'additions' => '0.00',
            'depreciation' => '0.00',
            'disposals_cost' => '0.00',
            'disposals_accumulated' => '0.00',
            'closing_cost' => '0.00',
            'closing_accumulated' => '0.00',
            'closing_book_value' => '0.00',
        ];

        foreach ($categories as $category) {
            $categoryRow = [
                'category_id' => $category->id,
                'category_name' => $category->name_ar,
                'opening_cost' => '0.00',
                'opening_accumulated' => '0.00',
                'opening_book_value' => '0.00',
                'additions' => '0.00',
                'depreciation' => '0.00',
                'disposals_cost' => '0.00',
                'disposals_accumulated' => '0.00',
                'closing_cost' => '0.00',
                'closing_accumulated' => '0.00',
                'closing_book_value' => '0.00',
            ];

            foreach ($category->assets as $asset) {
                $acquiredBeforePeriod = $asset->acquisition_date && $asset->acquisition_date->lt($dateFrom);
                $acquiredInPeriod = $asset->acquisition_date
                    && $asset->acquisition_date->gte($dateFrom)
                    && $asset->acquisition_date->lte($dateTo);

                // Opening balance: assets acquired before the period start
                if ($acquiredBeforePeriod) {
                    // Sum depreciation entries before period start to get opening accumulated
                    $openingAccumulated = (string) $asset->depreciationEntries()
                        ->where('period_end', '<', $dateFrom)
                        ->sum('amount');

                    $categoryRow['opening_cost'] = bcadd(
                        $categoryRow['opening_cost'],
                        (string) $asset->acquisition_cost,
                        2
                    );
                    $categoryRow['opening_accumulated'] = bcadd(
                        $categoryRow['opening_accumulated'],
                        $openingAccumulated,
                        2
                    );
                }

                // Additions: assets acquired during the period
                if ($acquiredInPeriod) {
                    $categoryRow['additions'] = bcadd(
                        $categoryRow['additions'],
                        (string) $asset->acquisition_cost,
                        2
                    );
                }

                // Depreciation during the period
                $periodDepreciation = (string) $asset->depreciationEntries()
                    ->where('period_end', '>=', $dateFrom)
                    ->where('period_end', '<=', $dateTo)
                    ->sum('amount');

                $categoryRow['depreciation'] = bcadd(
                    $categoryRow['depreciation'],
                    $periodDepreciation,
                    2
                );

                // Disposals during the period
                $disposals = $asset->disposals()
                    ->where('disposal_date', '>=', $dateFrom)
                    ->where('disposal_date', '<=', $dateTo)
                    ->get();

                foreach ($disposals as $disposal) {
                    $categoryRow['disposals_cost'] = bcadd(
                        $categoryRow['disposals_cost'],
                        (string) $asset->acquisition_cost,
                        2
                    );
                    $categoryRow['disposals_accumulated'] = bcadd(
                        $categoryRow['disposals_accumulated'],
                        (string) $disposal->accumulated_depreciation_at_disposal,
                        2
                    );
                }
            }

            // Calculate opening book value
            $categoryRow['opening_book_value'] = bcsub(
                $categoryRow['opening_cost'],
                $categoryRow['opening_accumulated'],
                2
            );

            // Closing cost = opening cost + additions - disposals cost
            $categoryRow['closing_cost'] = bcsub(
                bcadd($categoryRow['opening_cost'], $categoryRow['additions'], 2),
                $categoryRow['disposals_cost'],
                2
            );

            // Closing accumulated = opening accumulated + depreciation - disposals accumulated
            $categoryRow['closing_accumulated'] = bcsub(
                bcadd($categoryRow['opening_accumulated'], $categoryRow['depreciation'], 2),
                $categoryRow['disposals_accumulated'],
                2
            );

            // Closing book value = closing cost - closing accumulated
            $categoryRow['closing_book_value'] = bcsub(
                $categoryRow['closing_cost'],
                $categoryRow['closing_accumulated'],
                2
            );

            $report[] = $categoryRow;

            // Accumulate grand totals
            foreach ($grandTotals as $key => $value) {
                $grandTotals[$key] = bcadd($value, $categoryRow[$key], 2);
            }
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'categories' => $report,
            'totals' => $grandTotals,
        ];
    }
}
