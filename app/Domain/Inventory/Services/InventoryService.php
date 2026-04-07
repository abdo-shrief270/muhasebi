<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Record a purchase movement, update current_stock, recalculate weighted average cost.
     */
    public function recordPurchase(int $productId, int $qty, string $unitCost, ?array $reference = null): InventoryMovement
    {
        return DB::transaction(function () use ($productId, $qty, $unitCost, $reference) {
            $product = Product::lockForUpdate()->findOrFail($productId);

            $totalCost = bcmul((string) $qty, $unitCost, 2);

            // Recalculate weighted average cost
            $existingValue = bcmul((string) $product->current_stock, $product->purchase_price, 2);
            $newTotalValue = bcadd($existingValue, $totalCost, 2);
            $newTotalQty = $product->current_stock + $qty;

            if ($newTotalQty > 0) {
                $newAvgCost = bcdiv($newTotalValue, (string) $newTotalQty, 2);
                $product->purchase_price = $newAvgCost;
            }

            $product->current_stock = $newTotalQty;
            $product->save();

            return InventoryMovement::create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'movement_type' => MovementType::Purchase,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'reference_type' => $reference['type'] ?? null,
                'reference_id' => $reference['id'] ?? null,
                'notes' => $reference['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * Record a sale movement at current weighted average cost, update stock.
     * COGS = qty * weighted_avg_cost.
     */
    public function recordSale(int $productId, int $qty, ?array $reference = null): InventoryMovement
    {
        return DB::transaction(function () use ($productId, $qty, $reference) {
            $product = Product::lockForUpdate()->findOrFail($productId);

            $unitCost = $product->purchase_price; // weighted average cost
            $totalCost = bcmul((string) $qty, $unitCost, 2);

            $product->current_stock -= $qty;
            $product->save();

            return InventoryMovement::create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'movement_type' => MovementType::Sale,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'reference_type' => $reference['type'] ?? null,
                'reference_id' => $reference['id'] ?? null,
                'notes' => $reference['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * Positive or negative stock adjustment.
     */
    public function adjust(int $productId, int $qty, string $reason): InventoryMovement
    {
        return DB::transaction(function () use ($productId, $qty, $reason) {
            $product = Product::lockForUpdate()->findOrFail($productId);

            $unitCost = $product->purchase_price;
            $totalCost = bcmul((string) abs($qty), $unitCost, 2);

            $product->current_stock += $qty;
            $product->save();

            return InventoryMovement::create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'movement_type' => MovementType::Adjustment,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'notes' => $reason,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * Current stock levels, valuation per product.
     */
    public function getStockReport(array $filters = []): LengthAwarePaginator
    {
        $query = Product::with('category')
            ->select([
                'id',
                'tenant_id',
                'category_id',
                'sku',
                'name_ar',
                'name_en',
                'unit_of_measure',
                'purchase_price',
                'current_stock',
                'valuation_method',
            ])
            ->selectRaw('(current_stock * purchase_price) as stock_value');

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return $query->orderBy('sku')->paginate($perPage);
    }

    /**
     * Paginated movement history for a product.
     */
    public function getMovementHistory(int $productId, array $filters = []): LengthAwarePaginator
    {
        $query = InventoryMovement::with('createdByUser')
            ->where('product_id', $productId);

        if (! empty($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Products where current_stock <= reorder_level.
     */
    public function lowStockAlert(): Collection
    {
        return Product::active()
            ->lowStock()
            ->where('reorder_level', '>', 0)
            ->with('category')
            ->orderBy('current_stock')
            ->get();
    }

    /**
     * Total inventory value by category. Weighted average or FIFO.
     */
    public function valuationReport(): Collection
    {
        return Product::active()
            ->select('category_id')
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('SUM(current_stock) as total_units')
            ->selectRaw('SUM(current_stock * purchase_price) as total_value')
            ->with('category')
            ->groupBy('category_id')
            ->orderByDesc('total_value')
            ->get();
    }

    /**
     * Inventory turnover ratio per product for the given date range.
     * Turnover = COGS / Average Inventory Value
     */
    public function turnoverReport(string $from, string $to): Collection
    {
        return Product::active()
            ->with('category')
            ->get()
            ->map(function (Product $product) use ($from, $to) {
                // COGS = sum of total_cost for sale movements in the period
                $cogs = InventoryMovement::where('product_id', $product->id)
                    ->where('movement_type', MovementType::Sale)
                    ->whereBetween('created_at', [$from, $to])
                    ->sum('total_cost');

                // Average inventory value: use current purchase_price * current_stock as proxy
                // A more accurate approach would track opening + closing, but this serves the report
                $avgInventoryValue = bcmul((string) $product->current_stock, $product->purchase_price, 2);

                $turnoverRatio = '0.00';
                if (bccomp($avgInventoryValue, '0', 2) > 0) {
                    $turnoverRatio = bcdiv((string) $cogs, $avgInventoryValue, 2);
                }

                return [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name_ar' => $product->name_ar,
                    'name_en' => $product->name_en,
                    'category' => $product->category?->name_ar,
                    'cogs' => $cogs,
                    'avg_inventory_value' => $avgInventoryValue,
                    'turnover_ratio' => $turnoverRatio,
                ];
            })
            ->sortByDesc('turnover_ratio')
            ->values();
    }
}
