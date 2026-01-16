<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$duplicates = DB::table('purchase_items')
    ->select('purchase_id', 'product_id', DB::raw('COUNT(*) as count'))
    ->groupBy('purchase_id', 'product_id')
    ->havingRaw('COUNT(*) > 1')
    ->get();

echo "Found " . $duplicates->count() . " duplicate groups.\n";

foreach ($duplicates as $duplicate) {
    echo "Processing P:{$duplicate->purchase_id}, Pr:{$duplicate->product_id} (Count: {$duplicate->count})\n";

    $items = DB::table('purchase_items')
        ->where('purchase_id', $duplicate->purchase_id)
        ->where('product_id', $duplicate->product_id)
        ->orderBy('id')
        ->get();

    $keepId = $items->first()->id;
    $toDeleteIds = $items->slice(1)->pluck('id')->toArray();

    echo "  Keeping ID: {$keepId}, Deleting IDs: " . implode(',', $toDeleteIds) . "\n";

    // Update references
    DB::table('sale_items')->whereIn('purchase_item_id', $toDeleteIds)->update(['purchase_item_id' => $keepId]);
    DB::table('stock_adjustments')->whereIn('purchase_item_id', $toDeleteIds)->update(['purchase_item_id' => $keepId]);
    DB::table('sale_return_items')->whereIn('return_to_purchase_item_id', $toDeleteIds)->update(['return_to_purchase_item_id' => $keepId]);
    DB::table('stock_requisition_items')->whereIn('issued_from_purchase_item_id', $toDeleteIds)->update(['issued_from_purchase_item_id' => $keepId]);

    // Delete duplicates
    DB::table('purchase_items')->whereIn('id', $toDeleteIds)->delete();
}

echo "Cleanup complete.\n";
