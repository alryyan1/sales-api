<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$items = DB::table('purchase_items')
    ->where('purchase_id', 38)
    ->where('product_id', 4)
    ->get();

echo "Items found for 38/4: " . $items->count() . "\n";
foreach ($items as $item) {
    echo "ID: {$item->id}, Purchase: {$item->purchase_id}, Product: {$item->product_id}\n";
}
