<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$warehouseId = 999; // Use a non-existent warehouse ID to test fallback to 0
$product = Product::query()
    ->select('products.*')
    ->addSelect([
        'current_stock_quantity' => DB::table('product_warehouse')
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('product_id', 'products.id')
            ->where('warehouse_id', $warehouseId)
            ->limit(1)
    ])
    ->first();

if ($product) {
    echo "Product ID: " . $product->id . "\n";
    echo "Total Stock (per model): " . $product->stock_quantity . "\n";
    echo "Current Stock (override for WH 999): " . $product->current_stock_quantity . "\n";
    echo "Raw Attribute 'current_stock_quantity': " . ($product->getAttributes()['current_stock_quantity'] ?? 'NOT SET') . "\n";
} else {
    echo "No products found.\n";
}
