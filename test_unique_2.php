<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    echo "Dropping index if exists...\n";
    try {
        DB::statement('ALTER TABLE purchase_items DROP INDEX purchase_items_purchase_product_unique');
        echo "  Dropped.\n";
    } catch (\Exception $e) {
        echo "  Not found or error: " . $e->getMessage() . "\n";
    }

    echo "Adding unique index...\n";
    DB::statement('ALTER TABLE purchase_items ADD UNIQUE purchase_items_purchase_product_unique (purchase_id, product_id)');
    echo "Success!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if ($e instanceof \PDOException) {
        print_r($e->errorInfo);
    }
}
