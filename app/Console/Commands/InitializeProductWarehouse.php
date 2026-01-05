<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\Carbon;

class InitializeProductWarehouse extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:initialize-warehouse 
                            {--warehouse-id=1 : Warehouse ID to initialize} 
                            {--chunk=100 : Number of products to process at a time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize product_warehouse table with current product.stock_quantity for default warehouse';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting product warehouse initialization...');

        $warehouseId = (int) $this->option('warehouse-id');
        $chunkSize = (int) $this->option('chunk');

        // Validate warehouse exists
        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse) {
            $this->error("Warehouse ID {$warehouseId} not found.");
            return self::FAILURE;
        }

        $this->info("Using warehouse: {$warehouse->name} (ID: {$warehouse->id})");

        // Get existing product_warehouse records for this warehouse to skip them
        $existingProductIds = DB::table('product_warehouse')
            ->where('warehouse_id', $warehouseId)
            ->pluck('product_id')
            ->all();

        $this->info("Found " . count($existingProductIds) . " products already initialized for this warehouse.");

        // Get products that don't have a warehouse record yet
        $productsQuery = Product::whereNotIn('id', $existingProductIds);

        $totalProducts = $productsQuery->count();

        if ($totalProducts === 0) {
            $this->warn('No products found that need initialization. All products already have warehouse records.');
            return self::SUCCESS;
        }

        $this->info("Found {$totalProducts} products to initialize.");

        $bar = $this->output->createProgressBar($totalProducts);
        $bar->start();

        $initializedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $errors = [];

        // Process products in chunks
        $productsQuery->orderBy('id')->chunk($chunkSize, function ($products) use ($bar, $warehouseId, &$initializedCount, &$skippedCount, &$errorCount, &$errors) {
            foreach ($products as $product) {
                try {
                    // Double-check that the product doesn't already have a warehouse record
                    // (in case it was added between query and processing)
                    $exists = DB::table('product_warehouse')
                        ->where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->exists();

                    if ($exists) {
                        $skippedCount++;
                        $bar->advance();
                        continue;
                    }

                    // Insert into product_warehouse
                    DB::table('product_warehouse')->insert([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $product->stock_quantity ?? 0,
                        'min_stock_level' => null,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);

                    $initializedCount++;

                } catch (\Exception $e) {
                    $errorCount++;
                    $errorMessage = "Product ID {$product->id} ({$product->name}): " . $e->getMessage();
                    $errors[] = $errorMessage;
                    Log::error("Product Warehouse Initialization Failed: {$errorMessage}");
                }

                $bar->advance();
            }
        });

        $bar->finish();

        $this->newLine(2);
        $this->info('Product warehouse initialization complete!');
        $this->info("Successfully initialized: {$initializedCount} products.");
        
        if ($skippedCount > 0) {
            $this->warn("Skipped records: {$skippedCount} (already had warehouse records)");
        }

        if ($errorCount > 0) {
            $this->error("Errors encountered: {$errorCount}");
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        }

        return self::SUCCESS;
    }
}

