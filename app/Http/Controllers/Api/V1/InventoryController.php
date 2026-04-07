<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\Product;
use App\Domain\Inventory\Models\ProductCategory;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Services\ProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RecordMovementRequest;
use App\Http\Requests\Inventory\StoreProductCategoryRequest;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly InventoryService $inventoryService,
    ) {}

    // ──────────────────────────────────────
    // Categories
    // ──────────────────────────────────────

    public function categoryIndex(): JsonResponse
    {
        return $this->success($this->productService->listCategories());
    }

    public function categoryStore(StoreProductCategoryRequest $request): JsonResponse
    {
        $category = $this->productService->createCategory($request->validated());

        return $this->created($category);
    }

    public function categoryUpdate(StoreProductCategoryRequest $request, ProductCategory $productCategory): JsonResponse
    {
        $category = $this->productService->updateCategory($productCategory, $request->validated());

        return $this->success($category);
    }

    public function categoryDestroy(ProductCategory $productCategory): JsonResponse
    {
        $this->productService->deleteCategory($productCategory);

        return $this->deleted('Product category deleted successfully.');
    }

    // ──────────────────────────────────────
    // Products CRUD
    // ──────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $products = $this->productService->list([
            'search' => $request->query('search'),
            'is_active' => $request->query('is_active'),
            'category_id' => $request->query('category_id'),
            'low_stock' => $request->query('low_stock'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return $this->success($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create($request->validated());

        return $this->created($product->load('category'));
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'inventoryAccount', 'cogsAccount', 'revenueAccount']);

        return $this->success($product);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->productService->update($product, $request->validated());

        return $this->success($product->load('category'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return $this->deleted('Product deleted successfully.');
    }

    // ──────────────────────────────────────
    // Inventory Operations & Reports
    // ──────────────────────────────────────

    public function recordMovement(RecordMovementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $movement = match ($data['movement_type']) {
            'purchase' => $this->inventoryService->recordPurchase(
                (int) $data['product_id'],
                (int) $data['quantity'],
                (string) $data['unit_cost'],
                $data['reference_type'] ? ['type' => $data['reference_type'], 'id' => $data['reference_id'] ?? null, 'notes' => $data['notes'] ?? null] : null,
            ),
            'sale' => $this->inventoryService->recordSale(
                (int) $data['product_id'],
                (int) $data['quantity'],
                $data['reference_type'] ? ['type' => $data['reference_type'], 'id' => $data['reference_id'] ?? null, 'notes' => $data['notes'] ?? null] : null,
            ),
            'adjustment' => $this->inventoryService->adjust(
                (int) $data['product_id'],
                (int) $data['quantity'],
                $data['notes'] ?? 'Manual adjustment',
            ),
            default => $this->inventoryService->recordPurchase(
                (int) $data['product_id'],
                (int) $data['quantity'],
                (string) ($data['unit_cost'] ?? '0'),
            ),
        };

        return $this->created($movement->load('product'));
    }

    public function stockReport(Request $request): JsonResponse
    {
        $report = $this->inventoryService->getStockReport([
            'search' => $request->query('search'),
            'category_id' => $request->query('category_id'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return $this->success($report);
    }

    public function movements(Product $product, Request $request): JsonResponse
    {
        $movements = $this->inventoryService->getMovementHistory($product->id, [
            'movement_type' => $request->query('movement_type'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return $this->success($movements);
    }

    public function lowStockAlert(): JsonResponse
    {
        return $this->success($this->inventoryService->lowStockAlert());
    }

    public function valuation(): JsonResponse
    {
        return $this->success($this->inventoryService->valuationReport());
    }

    public function turnover(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $report = $this->inventoryService->turnoverReport(
            $request->query('from'),
            $request->query('to'),
        );

        return $this->success($report);
    }
}
