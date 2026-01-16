<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$items = DB::table('purchase_items')->get();
foreach ($items as $item) {
    echo "ID: {$item->id}, P: {$item->purchase_id}, Pr: {$item->product_id}\n";
}
