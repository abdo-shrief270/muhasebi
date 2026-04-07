<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\FixedAssets\Models\AssetCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class AssetCategoryService
{
    /**
     * List asset categories with search and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return AssetCategory::query()
            ->withCount('assets')
            ->search($filters['search'] ?? null)
            ->orderBy($filters['sort_by'] ?? 'name', $filters['sort_dir'] ?? 'asc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new asset category.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AssetCategory
    {
        $tenantId = (int) app('tenant.id');
        $data['tenant_id'] = $tenantId;

        $this->validateAccountsBelongToTenant($data, $tenantId);

        return AssetCategory::query()->create($data);
    }

    /**
     * Update an existing asset category.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AssetCategory $category, array $data): AssetCategory
    {
        $tenantId = (int) app('tenant.id');

        $this->validateAccountsBelongToTenant($data, $tenantId);

        $category->update($data);

        return $category->refresh();
    }

    /**
     * Delete an asset category (only if no assets belong to it).
     */
    public function delete(AssetCategory $category): void
    {
        if ($category->assets()->exists()) {
            throw ValidationException::withMessages([
                'category' => __('Cannot delete a category that still has assets.'),
            ]);
        }

        $category->delete();
    }

    /**
     * Validate that referenced account IDs belong to the current tenant.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateAccountsBelongToTenant(array $data, int $tenantId): void
    {
        $accountFields = [
            'asset_account_id',
            'depreciation_expense_account_id',
            'accumulated_depreciation_account_id',
        ];

        $accountIds = array_filter(
            array_map(fn (string $field) => $data[$field] ?? null, $accountFields)
        );

        if (empty($accountIds)) {
            return;
        }

        $validCount = Account::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->count();

        if ($validCount !== count($accountIds)) {
            throw ValidationException::withMessages([
                'accounts' => __('One or more account IDs do not belong to this tenant.'),
            ]);
        }
    }
}
