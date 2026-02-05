<?php

use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\Warehouse;

// 1. Create a dummy product and warehouse
$product = Product::factory()->create();
$warehouse = Warehouse::factory()->create();

// Ensure NO pivot exists
$product->warehouses()->detach($warehouse->id);

echo "Initial Pivot Exists: " . ($product->warehouses()->where('warehouse_id', $warehouse->id)->exists() ? 'Yes' : 'No') . "\n";

// 2. Create an inventory count
$count = InventoryCount::create([
    'warehouse_id' => $warehouse->id,
    'user_id' => 1,
    'count_date' => now(),
    'status' => 'completed',
]);

// 3. Add item with actual quantity 10
InventoryCountItem::create([
    'inventory_count_id' => $count->id,
    'product_id' => $product->id,
    'expected_quantity' => 0,
    'actual_quantity' => 10,
]);

// 4. Run the approve logic (simulated from model)
foreach ($count->items as $item) {
    if ($item->actual_quantity !== null) {
        $p = $item->product;
        $pivot = $p->warehouses()->where('warehouse_id', $count->warehouse_id)->first();

        if ($pivot) {
            echo "Pivot found, updating...\n";
            $p->warehouses()->updateExistingPivot($count->warehouse_id, [
                'quantity' => max(0, $item->actual_quantity)
            ]);
        } else {
            echo "Pivot NOT found. Problem detected!\n";
        }
    }
}

// Cleanup
$count->delete(); // This cascades? Check migration but okay for temp
$product->delete();
$warehouse->delete();
