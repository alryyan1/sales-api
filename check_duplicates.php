<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$duplicates = DB::table('purchase_items')
    ->select('purchase_id', 'product_id', DB::raw('COUNT(*) as count'), DB::raw('GROUP_CONCAT(id) as ids'))
    ->groupBy('purchase_id', 'product_id')
    ->havingRaw('COUNT(*) > 1')
    ->get();

echo "Duplicates found: " . $duplicates->count() . "\n";
foreach ($duplicates as $dup) {
    echo "Purchase: {$dup->purchase_id}, Product: {$dup->product_id}, Count: {$dup->count}, Ids: {$dup->ids}\n";
}
