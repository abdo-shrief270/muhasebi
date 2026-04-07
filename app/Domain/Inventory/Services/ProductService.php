<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\Product;
use App\Domain\Inventory\Models\ProductCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ProductService
{
    // ──────────────────────────────────────
    // Categories
    // ──────────────────────────────────────

    /** @return Collection<int, ProductCategory> */
    public function listCategories(): Collection
    {
        return ProductCategory::with('children')
            ->whereNull('parent_id')
            ->orderBy('name_ar')
            ->get();
    }

    public function createCategory(array $data): ProductCategory
    {
        return ProductCategory::create($data);
    }

    public function updateCategory(ProductCategory $category, array $data): ProductCategory
    {
        $category->update($data);

        return $category->refresh();
    }

    public function deleteCategory(ProductCategory $category): void
    {
        $category->delete();
    }

    // ──────────────────────────────────────
    // Products
    // ──────────────────────────────────────

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Product::with('category');

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['category_id'])) {
            $query->byCategory((int) $filters['category_id']);
        }

        if (! empty($filters['low_stock'])) {
            $query->lowStock();
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return $query->orderByDesc('updated_at')->paginate($perPage);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->refresh();
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    public function find(int $id): Product
    {
        return Product::with(['category', 'inventoryAccount', 'cogsAccount', 'revenueAccount'])->findOrFail($id);
    }
}
