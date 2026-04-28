<?php

declare(strict_types=1);

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Enums\ValuationMethod;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Product;
use App\Domain\Inventory\Models\ProductCategory;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tenant\Models\Tenant;

// ── Enum Tests ──

test('movement type labels are correct', function () {
    expect(MovementType::Purchase->label())->toBe('Purchase');
    expect(MovementType::Purchase->labelAr())->toBe('شراء');
    expect(MovementType::Sale->label())->toBe('Sale');
    expect(MovementType::Adjustment->label())->toBe('Adjustment');
    expect(MovementType::Transfer->label())->toBe('Transfer');
    expect(MovementType::ReturnIn->label())->toBe('Return In');
    expect(MovementType::ReturnOut->label())->toBe('Return Out');
});

test('valuation method labels are correct', function () {
    expect(ValuationMethod::WeightedAverage->label())->toBe('Weighted Average');
    expect(ValuationMethod::WeightedAverage->labelAr())->toBe('المتوسط المرجح');
    expect(ValuationMethod::Fifo->label())->toBe('FIFO');
    expect(ValuationMethod::Fifo->labelAr())->toBe('الوارد أولاً صادر أولاً');
});

test('movement type increases stock flag is correct', function () {
    expect(MovementType::Purchase->increasesStock())->toBeTrue();
    expect(MovementType::ReturnIn->increasesStock())->toBeTrue();
    expect(MovementType::Sale->increasesStock())->toBeFalse();
    expect(MovementType::ReturnOut->increasesStock())->toBeFalse();
});

// ── Weighted Average Cost Tests ──

test('weighted average: buy 10@100, buy 20@120 → avg cost = 113.33', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant.id', $tenant->id);

    $product = Product::factory()->for($tenant)->create([
        'current_stock' => 0,
        'purchase_price' => '0.00',
    ]);

    $service = app(InventoryService::class);

    // Buy 10 @ 100
    $service->recordPurchase($product->id, 10, '100.00');

    $product->refresh();
    expect($product->current_stock)->toBe(10);
    expect($product->purchase_price)->toBe('100.00');

    // Buy 20 @ 120
    $service->recordPurchase($product->id, 20, '120.00');

    $product->refresh();
    expect($product->current_stock)->toBe(30);
    // (10*100 + 20*120) / 30 = (1000 + 2400) / 30 = 3400 / 30 = 113.33
    expect($product->purchase_price)->toBe('113.33');
});

test('sale at weighted avg: sell 5 → COGS = 5 * 113.33 = 566.65', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant.id', $tenant->id);

    $product = Product::factory()->for($tenant)->create([
        'current_stock' => 30,
        'purchase_price' => '113.33',
    ]);

    $service = app(InventoryService::class);

    $movement = $service->recordSale($product->id, 5);

    $product->refresh();
    expect($product->current_stock)->toBe(25);
    expect($movement->unit_cost)->toBe('113.33');
    // 5 * 113.33 = 566.65
    expect($movement->total_cost)->toBe('566.65');
    expect($movement->movement_type)->toBe(MovementType::Sale);
});

test('stock update: buy 10, sell 3 → current_stock = 7', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant.id', $tenant->id);

    $product = Product::factory()->for($tenant)->create([
        'current_stock' => 0,
        'purchase_price' => '0.00',
    ]);

    $service = app(InventoryService::class);

    $service->recordPurchase($product->id, 10, '50.00');
    $service->recordSale($product->id, 3);

    $product->refresh();
    expect($product->current_stock)->toBe(7);
});

test('low stock: reorder_level 5, current_stock 3 → flagged', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant.id', $tenant->id);

    Product::factory()->for($tenant)->create([
        'reorder_level' => 5,
        'current_stock' => 3,
        'is_active' => true,
    ]);

    // Product above reorder level — should NOT be flagged
    Product::factory()->for($tenant)->create([
        'reorder_level' => 5,
        'current_stock' => 10,
        'is_active' => true,
    ]);

    $service = app(InventoryService::class);
    $alerts = $service->lowStockAlert();

    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->current_stock)->toBe(3);
});

test('turnover: COGS 60000, avg inventory 20000 → ratio 3.00', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant.id', $tenant->id);

    $product = Product::factory()->for($tenant)->create([
        'current_stock' => 200,
        'purchase_price' => '100.00', // avg inventory value = 200 * 100 = 20000
        'is_active' => true,
    ]);

    // Create sale movements totalling COGS = 60000. created_at isn't in
    // InventoryMovement's fillable (strict-mode rejects it on mass-assign),
    // so back-date by updating the timestamp column directly after insert.
    $movement1 = InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'product_id' => $product->id,
        'movement_type' => MovementType::Sale,
        'quantity' => 300,
        'unit_cost' => '100.00',
        'total_cost' => '30000.00',
    ]);
    $movement1->timestamps = false;
    $movement1->created_at = now()->subDays(10);
    $movement1->save();

    $movement2 = InventoryMovement::create([
        'tenant_id' => $tenant->id,
        'product_id' => $product->id,
        'movement_type' => MovementType::Sale,
        'quantity' => 300,
        'unit_cost' => '100.00',
        'total_cost' => '30000.00',
    ]);
    $movement2->timestamps = false;
    $movement2->created_at = now()->subDays(5);
    $movement2->save();

    $service = app(InventoryService::class);
    $report = $service->turnoverReport(
        now()->subMonth()->toDateString(),
        now()->toDateString(),
    );

    $productReport = $report->firstWhere('product_id', $product->id);
    expect($productReport)->not->toBeNull();
    expect($productReport['turnover_ratio'])->toBe('3.00');
});

test('adjustment changes stock correctly', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant.id', $tenant->id);

    $product = Product::factory()->for($tenant)->create([
        'current_stock' => 10,
        'purchase_price' => '50.00',
    ]);

    $service = app(InventoryService::class);

    // Positive adjustment
    $movement = $service->adjust($product->id, 5, 'Found extra stock');
    $product->refresh();
    expect($product->current_stock)->toBe(15);
    expect($movement->movement_type)->toBe(MovementType::Adjustment);
    expect($movement->notes)->toBe('Found extra stock');

    // Negative adjustment
    $service->adjust($product->id, -3, 'Damaged goods');
    $product->refresh();
    expect($product->current_stock)->toBe(12);
});

test('product category hierarchy works', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant.id', $tenant->id);

    $parent = ProductCategory::factory()->for($tenant)->create(['name_ar' => 'إلكترونيات']);
    $child = ProductCategory::factory()->for($tenant)->withParent($parent)->create(['name_ar' => 'هواتف']);

    expect($child->parent->id)->toBe($parent->id);
    expect($parent->children)->toHaveCount(1);
    expect($parent->children->first()->id)->toBe($child->id);
});

test('product scopes filter correctly', function () {
    $tenant = Tenant::factory()->create();
    app()->instance('tenant.id', $tenant->id);

    // Pin reorder_level/current_stock explicitly — the factory randomizes
    // both, which would let products 1 and 2 sneak into the lowStock scope
    // some runs and make this test flaky.
    $active = Product::factory()->for($tenant)->create([
        'is_active' => true,
        'reorder_level' => 5,
        'current_stock' => 100,
        'name_ar' => 'شاشة',
        'sku' => 'SCR-001',
    ]);
    Product::factory()->for($tenant)->create([
        'is_active' => false,
        'reorder_level' => 5,
        'current_stock' => 100,
        'name_ar' => 'طابعة',
        'sku' => 'PRT-001',
    ]);
    Product::factory()->for($tenant)->create([
        'is_active' => true,
        'reorder_level' => 20,
        'current_stock' => 5,
        'name_ar' => 'ماوس',
        'sku' => 'MOU-001',
    ]);

    expect(Product::active()->count())->toBe(2);
    expect(Product::lowStock()->count())->toBe(1);
    expect(Product::search('شاشة')->count())->toBe(1);
    expect(Product::search('SCR')->count())->toBe(1);
});
