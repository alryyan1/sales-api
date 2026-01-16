<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    DB::statement('ALTER TABLE purchase_items ADD UNIQUE purchase_items_purchase_product_unique (purchase_id, product_id)');
    echo "Success!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    if ($e instanceof \PDOException) {
        print_r($e->errorInfo);
    }
}
