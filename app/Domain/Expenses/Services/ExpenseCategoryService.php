<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Services;

use App\Domain\Expenses\Models\ExpenseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryService
{
    /**
     * List expense categories with search and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return ExpenseCategory::query()
            ->with(['glAccount'])
            ->when(
                isset($filters['search']),
                fn ($q) => $q->search($filters['search'])
            )
            ->when(
                isset($filters['is_active']),
                fn ($q) => $q->where('is_active', (bool) $filters['is_active'])
            )
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new expense category.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ExpenseCategory
    {
        return ExpenseCategory::query()->create([
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'description' => $data['description'] ?? null,
            'gl_account_id' => $data['gl_account_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * Update an expense category.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ExpenseCategory $category, array $data): ExpenseCategory
    {
        $category->update([
            'name' => $data['name'] ?? $category->name,
            'name_ar' => $data['name_ar'] ?? $category->name_ar,
            'description' => $data['description'] ?? $category->description,
            'gl_account_id' => $data['gl_account_id'] ?? $category->gl_account_id,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);

        return $category->refresh();
    }

    /**
     * Delete a category only if it has no expenses.
     *
     * @throws ValidationException
     */
    public function delete(ExpenseCategory $category): void
    {
        if ($category->expenses()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Cannot delete a category that has expenses. Deactivate it instead.'],
            ]);
        }

        $category->delete();
    }
}
