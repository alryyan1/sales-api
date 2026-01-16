<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$connections = ['mysql', 'wigdan', 'one_care', 'mirgani'];

foreach ($connections as $conn) {
    try {
        echo "Checking connection: {$conn}\n";
        $results = DB::connection($conn)->select("SELECT purchase_id, product_id, COUNT(*) as count FROM purchase_items GROUP BY purchase_id, product_id HAVING count > 1");
        if (empty($results)) {
            echo "  No duplicates found.\n";
        } else {
            foreach ($results as $row) {
                echo "  Duplicate: P:{$row->purchase_id}, Pr:{$row->product_id}, Count:{$row->count}\n";
            }
        }
    } catch (\Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}
